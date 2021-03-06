<form action="<?= $wgScript ?>?action=submit" method="POST">
<input type="hidden" name="wpEditToken" value="<?= htmlspecialchars($wgUser->getEditToken()) ?>" />
<input type="hidden" name="wpEdittime" value="<?= $aclArticle ? $aclArticle->getTimestamp() : '' ?>" />
<input type="hidden" name="wpStarttime" value="<?= wfTimestampNow() ?>" />
<input type="hidden" id="wpTitle" name="title" value="<?= $aclArticle ? htmlspecialchars($aclTitle->getPrefixedText()) : '' ?>" />
<table class="acle">
<tr>
 <td style="vertical-align: top; width: 500px">
  <p><b><?= wfMessage('hacl_edit_definition_text')->text() ?></b></p>
  <p><textarea id="acl_def" name="wpTextbox1" rows="6" style="width: 500px" onchange="AE.parse_make_closure()"><?= htmlspecialchars($aclContent) ?></textarea></p>
  <p><b><?= wfMessage('hacl_edit_definition_target')->text() ?></b></p>
  <p>
   <select id="acl_what" onchange="AE.target_change(true)" style="max-width: 200px">
    <?php foreach($this->aclTargetTypes as $t => $l) { ?>
     <optgroup label="<?= wfMessage('hacl_edit_'.$t)->text() ?>">
     <?php foreach($l as $k => $true) { ?>
      <option id="acl_what_<?= $k ?>" value="<?= $k ?>"><?= wfMessage("hacl_define_$k")->text() ?></option>
     <?php } ?>
     </optgroup>
    <?php } ?>
   </select>
   <input type="text" class="txt" autocomplete="off" id="acl_name" style="width: 290px" />
  </p>
 </td>
 <td style="vertical-align: top">
  <p><b><?= wfMessage('hacl_edit_modify_definition')->text() ?></b></p>
  <p>
   <select id="to_type" onchange="AE.to_type_change()" style="max-width: 200px">
    <option value="user"><?= wfMessage('hacl_edit_user')->text() ?></option>
    <option value="group"><?= wfMessage('hacl_edit_group')->text() ?></option>
    <option value="*"><?= wfMessage('hacl_edit_all')->text() ?></option>
    <option value="#"><?= wfMessage('hacl_edit_reg')->text() ?></option>
   </select>
   <input type="text" class="txt" id="to_name" style="width: 200px" autocomplete="off" />
   <a id="hacl_to_goto" href="#" target="_blank" style="display: none" title="">
    <img src="<?= $wgScriptPath ?>/resources/src/mediawiki.skinning/images/external-ltr.png" width="10" height="10" alt="&rarr;" />
   </a>
  </p>
  <p>
   <input type="checkbox" id="act_all" onclick="AE.act_change(this)" onchange="AE.act_change(this)" />
   <label for="act_all" id="act_label_all"><?= wfMessage('hacl_edit_action_all')->text() ?></label>
   <input type="checkbox" id="act_manage" onclick="AE.act_change(this)" onchange="AE.act_change(this)" />
   <label for="act_manage" id="act_label_manage"><?= wfMessage('hacl_edit_action_manage')->text() ?></label>
   <br />
   <?php foreach(explode(',', 'read,edit,create,delete,move') as $k) { ?>
   <input type="checkbox" id="act_<?= $k ?>" onclick="AE.act_change(this)" onchange="AE.act_change(this)" />
   <label for="act_<?= $k ?>" id="act_label_<?= $k ?>"><?= wfMessage("hacl_edit_action_$k")->text() ?></label>
   <?php } ?>
   <br />
   <input type="checkbox" id="act_template" onclick="AE.act_change(this)" onchange="AE.act_change(this)" />
   <label for="act_template" id="act_label_template"><?= wfMessage('hacl_edit_action_template')->text() ?></label>
  </p>
  <p>
   <label for="inc_acl"><?= wfMessage('hacl_edit_include_right')->text() ?></label>
   <input type="text" class="txt" id="inc_acl" />
   <input type="button" value="<?= wfMessage('hacl_edit_include_do')->text() ?>" onclick="AE.include_acl()" />
  </p>
  <div id="acl_embed" style="display: none"></div>
 </td>
</tr>
</table>
<p id="acl_pns">
 <span><a id="acl_pn" class="acl_pn" href="#"></a></span>
 <input type="submit" name="wpSave" value="<?= wfMessage($aclArticle ? 'hacl_edit_save' : 'hacl_edit_create')->text() ?>" id="wpSave" />&nbsp;<a id="acl_delete_link" href="<?= $aclArticle ? $aclTitle->getLocalUrl(array('action' => 'delete')) : '' ?>"><?= wfMessage('hacl_edit_delete')->text() ?></a>
</p>
<p id="acl_pnhint" class="acl_error" style="display: none"><?= wfMessage('hacl_edit_enter_name_first')->text() ?></p>
<p id="acl_exists_hint" class="acl_info" style="display: none"><?= wfMessage('hacl_edit_sd_exists')->text() ?></p>
<p id="acl_define_rights" class="acl_error"><?= wfMessage('hacl_edit_define_rights')->text() ?></p>
<p id="acl_define_manager" class="acl_error"></p>
<p id="acl_non_canonical" class="acl_error"></p>
</form>
