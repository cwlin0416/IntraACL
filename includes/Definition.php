<?php

if (!defined('MEDIAWIKI'))
    die("This file is part of the IntraACL extension. It is not a valid entry point.");

/*

For small databases:

1) Materialize everything
2) On first request load ALL grants for current user
   Memory consumption: O(n_groups_of_user + n_rights_of_user)
3) => Every check is a SINGLE hash check without DB round-trips
4) Update complexity: O((n_parent_groups + n_parent_rights) * n_users_in_group)
   Most complex update is the update of a low-level group

For big databases:

1) Still materialize everything. It is unlikely that for a given definition
   there will be a very big number of other definitions that use it. So update
   complexity should be OK.
2) But there can be a big (and growing) number of individually protected pages a given user can access.
   So loading ALL grants for current user can be very memory consuming.
   In PHP [5.4], each single integer stored in an array takes ~80 bytes
   => 1000 indexed defs will take 80kb, and 10000 - 800kb.
3) So we cache only N recent definitions for current user (N is configurable),
   and starting with (N+1)th we make DB queries for each protected element.
   Also we pre-cache rules for embedded elements (image/template/category links).

'Manage rights' use cases:

1) Create right/group and then be able to edit it
2) Allow/restrict protecting of individual pages in namespace/category
3) Allow some users to edit ALL right definitions
   Probably solvable with MW right 'sysop'

*/

/**
 * 'Definition' is either a Security Descriptor or a Group
 *
 * SD may be either a right definition for some protected element
 * (page, category, namespace) or just a template suited for inclusion
 * into other SD(s).
 * SD can contain (action, user) and (action, group) grants and/or inclusions
 * of other SDs.
 *
 * Group may contain users and/or other groups as its members, and also
 * users and/or other groups as their managers.
 */

class IACLDefinition implements ArrayAccess
{
    // Definition has no DB row by itself as it would be degenerate
    var $data = array();            // Object data
    var $collection;                // Remembered mass-fetch collection
    var $rw;                        // Is this a read-write (dirty) copy?
    static $clean = array();        // Clean object cache
    static $dirty = array();        // Dirty object cache

    static function newEmpty($type, $id)
    {
        $self = new self();
        $self->rw = true;
        $self->data['pe_type'] = $type;
        $self->data['pe_id'] = $id;
        $self->data['rules'] = array();
        return $self;
    }

    static function newFromTitles($titles)
    {
        $where = array();
        foreach ($titles as &$k)
        {
            // FIXME: resolve multiple IDs at once
            // id = get_id(name, type)
            $pe = self::nameOfPE($k);
            $id = self::peIDforName($pe[0], $pe[1]);
            if ($id)
            {
                $where[] = array($pe[0], $id);
            }
            $k = array($pe[0], $pe[1], $id, $k);
        }
        $defs = self::select(array('pe' => $where));
        $r = array();
        foreach ($titles as &$k)
        {
            if ($k[2])
            {
                $r[$k[3]] = $defs[$k[0].'-'.$k[2]];
            }
        }
        return $r;
    }

    static function newFromName($peType, $peName)
    {
        $id = self::peIDforName($peType, $peName);
        if ($id)
        {
            $def = self::select(array('pe' => array($peType, $id)));
            if ($def)
            {
                $def = reset($def);
            }
            else
            {
                $def = self::newEmpty();
                $def['pe_type'] = $peType;
                $def['pe_id'] = $id;
            }
            return $def;
        }
        return false;
    }

    function offsetGet($k)
    {
        if (isset($this->data[$k]))
        {
            return $this->data[$k];
        }
        $m = 'get_'.$k;
        return $this->$m();
    }

    function offsetSet($k, $v)
    {
        if (!$this->rw)
        {
            $this->makeDirty();
        }
        if ($k == 'child_ids')
        {
            unset($this->data['children']);
        }
        return $this->data[$k] = $v;
    }

    function offsetExists($k)
    {
        return $k == 'pe_id' || $k == 'pe_type' || isset($this->data[$k]) || method_exists($this, 'get_'.$k);
    }

    function offsetUnset($k)
    {
        return $this->offsetSet($k, NULL);
    }

    /**
     * $where['pe'] = array(array(<pe_type>, <pe_id>), ...)
     */
    static function select($where, $options = array())
    {
        $byid = array();
        if (isset($where['pe']))
        {
            $pe = $where['pe'];
            unset($where['pe']);
            if (!$pe)
            {
                return array();
            }
            if (!is_array(@$pe[0]))
            {
                $pe = array($pe);
            }
            foreach ($pe as $i => $id)
            {
                $key = $id[0].'-'.$id[1];
                if (isset(self::$clean[$key]))
                {
                    $byid[$key] = self::$clean[$key];
                    unset($pe[$i]);
                }
            }
            if (!$pe)
            {
                // All objects already fetched from cache
                return $byid;
            }
            foreach ($pe as &$p)
            {
                $p = '('.intval($p[0]).', '.intval($p[1]).')';
            }
            $where[] = '(pe_type, pe_id) IN ('.implode(', ', $pe).')';
        }
        $rules = IACLStorage::get('SD')->getRules($where);
        $coll = array();
        foreach ($rules as $rule)
        {
            $key = $rule['pe_type'].'-'.$rule['pe_id'];
            if (!isset($byid[$key]))
            {
                self::$clean[$key] = $coll[$key] = $byid[$key] = $obj = new self();
                $obj->data['pe_type'] = $rule['pe_type'];
                $obj->data['pe_id'] = $rule['pe_id'];
                $obj->collection = &$coll;
            }
            else
            {
                $obj = $byid[$key];
            }
            $obj->data['rules'][$rule['child_type']][$rule['child_id']] = $rule;
        }
        return $coll;
    }

    protected function get_key()
    {
        return $this->data['pe_type'].'-'.$this->data['pe_id'];
    }

    /**
     * Get SDs that directly include this SD. Fetches them massively.
     */
    protected function get_parents()
    {
        $sds = $this->collection ?: array($this['key'] => $this);
        $ids = array();
        foreach ($sds as $sd)
        {
            if (!isset($sd->data['parents']))
            {
                $ids[] = '('.$sd->data['pe_type'].', '.$sd->data['pe_id'].')';
                $sd->data['parents'] = array();
            }
        }
        $rules = IACLStorage::get('SD')->getRules(array(
            "(child_type, child_id) IN ($ids)",
            'rule_type' => self::ACTION_INCLUDE_SD,
            'is_direct' => 1,
        ));
        $ids = array();
        $keys = array();
        foreach ($rules as $r)
        {
            $ids[$r['pe_type'].'-'.$r['pe_id']] = array($r['pe_type'], $r['pe_id']);
            $keys[$r['pe_type'].'-'.$r['pe_id']][] = $r['child_type'].'-'.$r['child_id'];
        }
        $parents = self::select(array('pe' => array_values($ids)));
        foreach ($parents as $parent)
        {
            foreach ($keys[$key = $parent['pe_type'].'-'.$parent['pe_id']] as $child_key)
            {
                $sds[$child_key]->data['parents'][$key] = $parent;
            }
        }
        return $this->data['parents'];
    }

    /**
     * Get SDs directly included by this SD. Fetches them massively.
     */
    protected function get_children()
    {
        $sds = $this->collection ?: array($this['key'] => $this);
        $ids = array();
        foreach ($sds as $sd)
        {
            if (!isset($sd->data['children']))
            {
                $ids[] = '('.$sd->data['pe_type'].', '.$sd->data['pe_id'].')';
                $sd->data['children'] = array();
            }
        }
        $rules = IACLStorage::get('SD')->getRules(array(
            "(pe_type, pe_id) IN ($ids)",
            'rule_type' => self::ACTION_INCLUDE_SD,
            'is_direct' => 1,
        ));
        $ids = array();
        $keys = array();
        foreach ($rules as $r)
        {
            $ids[$r['child_type'].'-'.$r['child_id']] = array($r['child_type'], $r['child_id']);
            $keys[$r['child_type'].'-'.$r['child_id']][] = $r['pe_type'].'-'.$r['pe_id'];
        }
        $children = self::select(array('pe' => array_values($ids)));
        foreach ($children as $child)
        {
            foreach ($keys[$key = $child['pe_type'].'-'.$child['pe_id']] as $parent_key)
            {
                $sds[$parent_key]->data['children'][$key] = $child;
            }
        }
        return $this->data['children'];
    }

    // Returns array(user_id => rule)
    protected function get_user_rights()
    {
        return $this['rules'][IACL::PE_USER];
    }

    // Returns array(group_id => rule)
    protected function get_group_rights()
    {
        return $this['rules'][IACL::PE_GROUP];
    }

    // Returns array('<type>-<id>' => rule)
    protected function get_child_ids()
    {
        $r = $this['rules'];
        $res = array();
        foreach (array(IACL::PE_CATEGORY, IACL::PE_PAGE, IACL::PE_NAMESPACE, IACL::PE_RIGHT) as $k)
        {
            if (isset($r[$k]))
            {
                foreach ($r[$k] as $sd => $rule)
                {
                    $res["$k-$sd"] = $rule;
                }
            }
        }
        return $res;
    }

    /**
     * Checks whether this SD only includes SINGLE predefined right and
     * does not include any inline rights or manage template rights.
     * If so, the ID of this single predefined right is returned.
     * If not, NULL is returned.
     */
    protected function get_single_child()
    {
        if (!$this['user_rights'] &&
            !$this['group_rights'] &&
            count($i = $this['child_ids']) == 1)
        {
            return reset($i);
        }
        return NULL;
    }

    function makeDirty()
    {
        if (!$this->rw)
        {
            self::$dirty[$this->data['pe_type'].'-'.$this->data['pe_id']] = $this;
            self::$clean[$this->data['pe_type'].'-'.$this->data['pe_id']] = clone $this;
            $this->collection = NULL;
            $this->rw = true;
        }
    }

    function dirty()
    {
        if ($this->rw)
        {
            return $this;
        }
        elseif (!isset(self::$dirty[$key = $this->data['pe_type'].'-'.$this->data['pe_id']]))
        {
            self::$dirty[$key] = clone $this;
            self::$dirty[$key]->rw = true;
            self::$dirty[$key]->collection = NULL;
        }
        return self::$dirty[$key];
    }

    function clean()
    {
        if ($this->rw)
        {
            if (isset(self::$clean[$key = $this->data['pe_type'].'-'.$this->data['pe_id']]))
            {
                return self::$clean[$key];
            }
            return false;
        }
        return $this;
    }

    /**
     * Check (with clever caching) if given user is granted some action
     * in the definition identified by $peType/$peID.
     *
     * @param int $userID       User ID or 0 for anonymous user
     * @param int $peType       Parent right type, one of IACL::PE_*
     * @param int/array $peID   Parent right ID(s)
     * @param int $actionID     Action ID, one of IACL::ACTION_*
     * @return int              1 = allow, 0 = deny, -1 = don't care
     */
    static function userCan($userID, $peType, $peID, $actionID)
    {
        static $userCache = array();
        // $loaded[$userID] is a bitmask:
        // 0x01 => SDs loaded
        // 0x02 => Groups preloaded
        // 0x04 => SDs incomplete
        // 0x08 => Groups incomplete
        static $loaded = array();
        if ($userID < 0)
        {
            $userID = 0;
        }
        $actionID |= ($actionID << IACL::INDIRECT_OFFSET);
        foreach ((array)$peID as $id)
        {
            if (isset($userCache[$userID][$peType][$id]))
            {
                return ($userCache[$userID][$peType][$id] & $actionID) ? 1 : 0;
            }
        }
        if ($userID)
        {
            // Fallback chain: current user -> registered users (0) -> all users (-1)
            $applicable = array($userID, IACL::ALL_USERS, IACL::REGISTERED_USERS);
        }
        else
        {
            $applicable = IACL::ALL_USERS;
        }
        $where = array(
            'child_type' => IACL::PE_USER,
            'child_id' => $applicable,
        );
        $options = array(
            'ORDER BY' => 'child_id DESC, pe_type ASC, pe_id DESC'
        );
        $isGroup = ($peType == IACL::PE_GROUP);
        if (!isset($loaded[$userID]) ||
            !($loaded[$userID] & (1 << $isGroup)))
        {
            global $iaclPreloadLimit;
            $loaded[$userID] |= (1 << $isGroup);
            // Preload up to $iaclPreloadLimit rules, preferring more general (pe_type ASC)
            // and more recent (pe_id DESC) rules for better cache hit ratio.
            // Groups are unused in permission checks and thus have no effect on permission check speed,
            // so don't preload them until explicitly requested
            if ($peType != IACL::PE_GROUP)
            {
                $where['pe_type'] = IACL::PE_GROUP;
            }
            else
            {
                $where[] = 'pe_type != '.IACL::PE_GROUP;
            }
            $options['LIMIT'] = $iaclPreloadLimit;
            $rules = IACLStorage::get('SD')->getRules($where, $options);
            if (count($rules) >= $iaclPreloadLimit)
            {
                // There are exactly $iaclPreloadLimit rules
                // => we assume there can be more
                $loaded[$userID] |= (4 << $isGroup);
            }
            foreach ($rules as $rule)
            {
                if (!isset($userCache[$userID][$rule['pe_type']][$rule['pe_id']]))
                {
                    $userCache[$userID][$rule['pe_type']][$rule['pe_id']] = $rule['actions'];
                }
            }
        }
        if (($loaded[$userID] & (4 << $isGroup)))
        {
            // Not all rules were preloaded => database is very big, perform additional query
            $where['pe_type'] = $peType;
            $where['pe_id'] = $peID;
            $rules = IACLStorage::get('SD')->getRules($where, $options);
            foreach ($rules as $rule)
            {
                if (!isset($userCache[$userID][$rule['pe_type']][$rule['pe_id']]))
                {
                    $userCache[$userID][$rule['pe_type']][$rule['pe_id']] = $rule['actions'];
                }
            }
        }
        foreach ((array)$peID as $id)
        {
            if (isset($userCache[$userID][$peType][$id]))
            {
                return ($userCache[$userID][$peType][$id] & $actionID) ? 1 : 0;
            }
        }
        return -1;
    }

    /**
     * Returns the ID of a protection object that is given by its name.
     * The ID depends on the type.
     *
     * @param  string $peName   Object name
     * @param  int $peType      Object type (IACL::PE_*)
     * @return int/bool         Object id or <false> if it does not exist
     */
    public static function peIDforName($peType, $peName)
    {
        $ns = NS_MAIN;
        if ($peType === IACL::PE_NAMESPACE)
        {
            // $peName is a namespace => get its ID
            global $wgContLang;
            $peName = str_replace(' ', '_', trim($peName, " _\t\n\r"));
            $idx = $wgContLang->getNsIndex($peName);
            if ($idx == false)
                return (strtolower($peName) == 'main') ? 0 : false;
            return $idx;
        }
        elseif ($peType === IACL::PE_RIGHT)
            $ns = HACL_NS_ACL;
        elseif ($peType === IACL::PE_CATEGORY)
            $ns = NS_CATEGORY;
        elseif ($peType === IACL::PE_USER)
            $ns = NS_USER;
        elseif ($peType === IACL::PE_GROUP)
        {
            $ns = HACL_NS_ACL;
            $peName = "Group/$peName";
        }
        // Return the page id
        // TODO add caching here
        $id = haclfArticleID($peName, $ns);
        return $id ? $id : false;
    }

    /**
     * Tries to get definition by its composite ID (type, ID).
     *
     * @param  int $peID    ID of the protected element
     * @param  int $peType  Type of the protected element
     * @return object|bool  Definition object or <false> if it does not exist
     */
    public static function getSDForPE($peID, $peType)
    {
        $r = self::select(array('pe' => array($peType, $peID)));
        return $r ? $r[0] : false;
    }

    /**
     * Determine protected element name and type by definition page title
     *
     *  ACL:Page/<Page title>               PE_PAGE
     *  ACL:Category/<Category name>        PE_CATEGORY
     *  ACL:Namespace/<Namespace name>      PE_NAMESPACE
     *  ACL:Namespace/Main                  PE_NAMESPACE
     *  ACL:Group/<Group name>              PE_GROUP
     *  ACL:<Right template name>           PE_RIGHT
     *
     * @param string/Title $defTitle            Definition title, with or without ACL: namespace
     * @return array(int $type, string $name)   Name of the protected element and its type.
     */
    public static function nameOfPE($defTitle)
    {
        global $wgContLang, $haclgContLang;
        if ($defTitle instanceof Title)
        {
            if ($defTitle->getNamespace() != HACL_NS_ACL)
            {
                return false;
            }
            $defTitle = $defTitle->getText();
        }
        else
        {
            // Ignore the namespace
            $ns = $wgContLang->getNsText(HACL_NS_ACL).':';
            if (strpos($defTitle, $ns) === 0)
            {
                $defTitle = substr($defTitle, strlen($ns));
            }
        }
        $p = strpos($defTitle, '/');
        if (!$p)
        {
            return array(IACL::PE_RIGHT, $defTitle);
        }
        $prefix = substr($defTitle, 0, $p);
        $type = $haclgContLang->getPetAlias($prefix);
        if ($type != IACL::PE_RIGHT)
        {
            $peName = substr($defTitle, $p+1);
            return array($type, $peName);
        }
        // Right by default
        return array(IACL::PE_RIGHT, $defTitle);
    }

    /**
     * Determine ACL definition page title by protected element type and name
     *
     * @param string $nameOfPE  PE name
     * @param string $peType    PE type
     * @return string $defTitle Definition title
     */
    public static function nameOfSD($nameOfPE, $peType)
    {
        global $wgContLang, $haclgContLang;
        $defTitle = $wgContLang->getNsText(HACL_NS_ACL).':';
        $prefix = $haclgContLang->getPetPrefix($peType);
        if ($prefix)
        {
            $defTitle .= $prefix.'/';
        }
        return $defTitle . $nameOfPE;
    }

    /**
     * Saves this definition into database
     */
    public function save(&$preventLoop = array())
    {
        // Load ID and parents before saving, as the definition may be deleted next
        $parents = $this->getDirectParents();
        $peType = $this['pe_type'];
        $peID = $this['pe_id'];
        $key = $peType.'-'.$peID;
        $st = IACLStorage::get('SD');
        if (!$this->data['rules'])
        {
            // Delete definition
            $this->data = array();
            $delRules = array(array('pe_type' => $peType, 'pe_id' => $peID));
            $addRules = array();
        }
        else
        {
            // Update definition
            if (isset($this->data['user_rights']) ||
                isset($this->data['group_rights']) ||
                isset($this->data['child_ids']))
            {
                list($delRules, $addRules) = $this->diffRules();
                if ($delRules)
                {
                    $st->deleteRules($delRules);
                }
                if ($addRules)
                {
                    $st->dataRules($addRules);
                }
            }
        }
        // Commit new state into cache
        self::$clean[$key] = $this;
        unset(self::$dirty[$key]);
        // Invalidate parents - they will do the same recursively for their parents and so on
        $preventLoop[$key] = true;
        foreach ($parents as $p)
        {
            if (!isset($preventLoop[$p['key']]))
            {
                $p->save($preventLoop);
            }
        }
        // TODO Invalidate cache (if any)
    }

    public function diffRules()
    {
        $oldRules = $this->clean();
        $oldRules = $oldRules ? $oldRules['rules'] : array();
        $addRules = $this->data['rules'] = $this->buildRules();
        foreach ($oldRules as $k => $rule)
        {
            if (isset($addRules[$k]) && $addRules[$k]['actions'] == $rule['actions'])
            {
                unset($addRules[$k]);
                unset($oldRules[$k]);
            }
        }
        return array($oldRules, $addRules);
    }

    protected function buildRules()
    {
        $rules = array();
        $directMask = ((1 << IACL::INDIRECT_OFFSET)-1);
        $childIds = array();
        $thisId = array(
            'pe_type'   => $this['pe_type'],
            'pe_id'     => $this['pe_id'],
        );
        // Process direct grants
        foreach ($this->data['rules'] as $childType => $children)
        {
            foreach ($children as $child => $actions)
            {
                $actions = $directMask & (is_array($actions) ? $actions['actions'] : $actions);
                if ($actions)
                {
                    if ($childType != IACL::PE_USER)
                    {
                        $childIds[] = array($childType, $child);
                    }
                    if ($thisId != IACL::PE_GROUP && ($childType == IACL::PE_USER || $childType == IACL::PE_GROUP))
                    {
                        // Edit right implies read right
                        if ($actions & IACL::ACTION_EDIT)
                        {
                            $actions |= IACL::ACTION_READ;
                        }
                    }
                    $rules[$childType][$child] = $thisId + array(
                        'child_type'    => $childType,
                        'child_id'      => $child,
                        'actions'       => $actions & $directMask,
                    );
                }
            }
        }
        // Process indirect grants
        $children = self::select(array('pe' => $childIds));
        $member = IACL::ACTION_GROUP_MEMBER | (IACL::ACTION_GROUP_MEMBER << IACL::INDIRECT_OFFSET);
        foreach ($childIds as $child)
        {
            if ($child['pe_type'] == IACL::PE_GROUP)
            {
                // Groups may be included in other groups or in right definitions
                $actions = $rules[$child['pe_type']][$child['pe_id']]['actions'] << IACL::INDIRECT_OFFSET;
                foreach ($child['rules'] as $rule)
                {
                    // Only take member rules into account
                    if ($rule['actions'] & $member)
                    {
                        if (!isset($rules[$rule['child_type']][$rule['child_id']]))
                        {
                            $rules[$rule['child_type']][$rule['child_id']] = $thisId + array(
                                'child_type' => $rule['child_type'],
                                'child_id'   => $rule['child_id'],
                                'actions'    => $actions,
                            );
                        }
                        else
                        {
                            $rules[$rule['child_type']][$rule['child_id']]['actions'] |= $actions;
                        }
                    }
                }
            }
            elseif ($this['pe_type'] != IACL::PE_GROUP)
            {
                // Right definitions can only be included into other right definitions
                foreach ($child['rules'] as $rule)
                {
                    // Make all rights indirect
                    $actions = (($rule['actions'] & $directMask) << IACL::INDIRECT_OFFSET) |
                        ($rule['actions'] & ($directMask << IACL::INDIRECT_OFFSET));
                    if (!isset($rules[$rule['child_type']][$rule['child_id']]))
                    {
                        $rules[$rule['child_type']][$rule['child_id']] = $thisId + array(
                            'child_type' => $rule['child_type'],
                            'child_id'   => $rule['child_id'],
                            'actions'    => $actions,
                        );
                    }
                    else
                    {
                        $rules[$rule['child_type']][$rule['child_id']]['actions'] |= $actions;
                    }
                }
            }
        }
        // Add empty ALL_USERS grant if not yet
        if (!isset($rules[IACL::PE_USER][IACL::ALL_USERS]))
        {
            $rules[IACL::PE_USER][IACL::ALL_USERS] = $thisId + array(
                'child_type' => IACL::PE_USER,
                'child_id'   => IACL::ALL_USERS,
                'actions'    => 0,
            );
        }
        return $rules;
    }
}
