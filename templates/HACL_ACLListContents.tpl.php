<input type="hidden" id="totalPages" value="<?= ceil($total/$limit) ?>" />
<input type="hidden" id="pageUrl" value="<?= $pageurl ?>" />
<?php if (!$lists) { ?>
<?= wfMessage('hacl_acllist_empty')->text() ?>
<?php } if ($prevpage) { ?>
<p><a href="<?= $prevpage ?>" onclick="change_page(<?= intval($offset/$limit-1) ?>); return false;"><?= wfMessage('hacl_acllist_prev')->text() ?></a></p>
<?php }
foreach (array('default', 'namespace', 'category', 'right', 'template', 'special', 'page') as $k) {
 if (!empty($lists[$k])) { ?>
 <?= wfMessage('hacl_acllist_'.$k)->text() ?>
 <ul>
  <?php foreach ($lists[$k] as $d) { ?>
   <li>
    <a title="<?= htmlspecialchars($d['name']) ?>" href="<?= $d['editlink'] ?>"><?= htmlspecialchars($d['real']) ?></a>
    <?php if ($d['single']) { ?>
     = <a title="<?= htmlspecialchars($d['singletip']) ?>" href="<?= $d['singlelink'] ?>"><?= htmlspecialchars($d['singlename']) ?></a>
    <?php } ?>
    &nbsp;<a title="<?= wfMessage('hacl_acllist_view')->text() ?>" href="<?= $d['viewlink'] ?>"><img src="<?= $haclgHaloScriptPath ?>/skins/images/view.png" /></a>
    <a title="<?= wfMessage('hacl_acllist_edit')->text() ?>" href="<?= $d['editlink'] ?>"><img src="<?= $haclgHaloScriptPath ?>/skins/images/edit.png" /></a>
   </li>
  <?php } ?>
 </ul>
 <?php }
}
if ($nextpage) { ?>
<p><a href="<?= $nextpage ?>" onclick="change_page(<?= intval(1+$offset/$limit) ?>); return false;"><?= wfMessage('hacl_acllist_next')->text() ?></a></p>
<?php }
