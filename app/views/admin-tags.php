<section class="o8-info-group o8-info-group--soft" aria-labelledby="tagAdminTitle">
<h2 class="h5" id="tagAdminTitle"><?= h(tr('admin.manageTags')) ?></h2>
<p class="small text-secondary"><?= h(tr('admin.tagInfo')) ?></p>
<form method="post" class="o8-info-group o8-info-group--head mb-3"><?php postFields('tag_create','settings'); ?>
<label class="form-label d-block"><?= h(tr('admin.newTag')) ?><input class="form-control" name="name" required maxlength="190"></label>
<button class="btn btn-primary" type="submit"><?= h(tr('admin.addTag')) ?></button></form>
<form method="get" class="o8-info-group o8-info-group--soft d-flex flex-wrap align-items-end gap-2 mb-3"><input type="hidden" name="section" value="settings"><?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?>
<label class="form-label flex-grow-1 mb-0"><?= h(tr('admin.searchTags')) ?><input class="form-control" type="search" name="q" value="<?= h(is_string($_GET['q']??null)?$_GET['q']:'') ?>" maxlength="190"></label><button class="btn btn-surface" type="submit"><?= h(tr('common.search')) ?></button></form>
<?php if (!$tagCatalogue): ?><p><?= h(tr('admin.noMatchingTagsCatalog')) ?></p><?php endif ?>
<div class="tag-settings-list">
<?php foreach ($tagCatalogue as $tag): ?><div class="tag-settings-row">
<?php if ($tag['manageable']): ?><form method="post" class="tag-settings-rename" id="tagRename<?= h($tag['id']) ?>"><?php postFields('tag_rename','settings'); ?><input type="hidden" name="id" value="<?= h($tag['id']) ?>"><input class="form-control form-control-sm" name="name" aria-label="<?= h(tr('admin.tagRenameAria',['name'=>$tag['name']])) ?>" value="<?= h($tag['name']) ?>" required maxlength="190"></form><span class="tag-settings-count" title="<?= h(tr('admin.assignedDocumentsTitle',['count'=>$tag['document_count']])) ?>"><?= h($tag['document_count']) ?> <?= h(tr('admin.documentsAbbrev')) ?></span><button class="btn btn-sm btn-surface" type="submit" form="tagRename<?= h($tag['id']) ?>"><?= h(tr('common.save')) ?></button>
<form method="post" class="tag-settings-delete" data-tag-delete data-tag-name="<?= h($tag['name']) ?>" data-tag-count="<?= h($tag['document_count']) ?>"><?php postFields('tag_delete','settings'); ?><input type="hidden" name="id" value="<?= h($tag['id']) ?>"><input type="hidden" name="document_count" value="<?= h($tag['document_count']) ?>"><input type="hidden" name="confirm" value=""><button class="btn btn-sm btn-outline-danger" type="submit"><?= h(tr('admin.deleteShort')) ?></button></form>
<?php else: ?><input class="form-control form-control-sm tag-settings-readonly" aria-label="<?= h(tr('admin.tagReadonlyAria',['name'=>$tag['name']])) ?>" value="<?= h($tag['name']) ?>" readonly><span class="tag-settings-count" title="<?= h(tr('admin.ownAssignedDocuments')) ?>"><?= h($tag['document_count']) ?> <?= h(tr('admin.documentsAbbrev')) ?></span><button class="btn btn-sm btn-surface" type="button" disabled title="<?= h(tr('admin.usedByOthersAdminOnly')) ?>"><?= h(tr('common.save')) ?></button><button class="btn btn-sm btn-outline-danger" type="button" disabled title="<?= h(tr('admin.usedByOthersAdminOnly')) ?>"><?= h(tr('admin.deleteShort')) ?></button><?php endif ?>
</div><?php endforeach ?>
</div>
<?php if (count($tagCatalogue)===500): ?><p class="small text-secondary mt-2"><?= h(tr('admin.tagResultsTruncated')) ?></p><?php endif ?>
</section>
