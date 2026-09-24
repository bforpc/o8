<section class="o8-info-group o8-info-group--soft" aria-labelledby="tagAdminTitle">
<h2 class="h5" id="tagAdminTitle">Tags verwalten</h2>
<p class="small text-secondary">Tags gelten für diesen Mandanten. Umbenennen erhält alle Zuordnungen; Löschen entfernt das Tag auch von zugeordneten Dokumenten im Papierkorb. Gemeinsam genutzte Tags kann nur ein Admin ändern oder löschen.</p>
<form method="post" class="o8-info-group o8-info-group--head mb-3"><?php postFields('tag_create','settings'); ?>
<label class="form-label d-block">Neues Tag<input class="form-control" name="name" required maxlength="190"></label>
<button class="btn btn-primary" type="submit">Tag hinzufügen</button></form>
<form method="get" class="o8-info-group o8-info-group--soft d-flex flex-wrap align-items-end gap-2 mb-3"><input type="hidden" name="section" value="settings"><?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?>
<label class="form-label flex-grow-1 mb-0">Tags suchen<input class="form-control" type="search" name="q" value="<?= h(is_string($_GET['q']??null)?$_GET['q']:'') ?>" maxlength="190"></label><button class="btn btn-surface" type="submit">Suchen</button></form>
<?php if (!$tagCatalogue): ?><p>Keine passenden Tags vorhanden.</p><?php endif ?>
<div class="tag-settings-list">
<?php foreach ($tagCatalogue as $tag): ?><div class="tag-settings-row">
<?php if ($tag['manageable']): ?><form method="post" class="tag-settings-rename" id="tagRename<?= h($tag['id']) ?>"><?php postFields('tag_rename','settings'); ?><input type="hidden" name="id" value="<?= h($tag['id']) ?>"><input class="form-control form-control-sm" name="name" aria-label="Tag <?= h($tag['name']) ?> umbenennen" value="<?= h($tag['name']) ?>" required maxlength="190"></form><span class="tag-settings-count" title="<?= h($tag['document_count']) ?> zugeordnete Dokumente"><?= h($tag['document_count']) ?> Dok.</span><button class="btn btn-sm btn-surface" type="submit" form="tagRename<?= h($tag['id']) ?>">Speichern</button>
<form method="post" class="tag-settings-delete" data-tag-delete data-tag-name="<?= h($tag['name']) ?>" data-tag-count="<?= h($tag['document_count']) ?>"><?php postFields('tag_delete','settings'); ?><input type="hidden" name="id" value="<?= h($tag['id']) ?>"><input type="hidden" name="document_count" value="<?= h($tag['document_count']) ?>"><input type="hidden" name="confirm" value=""><button class="btn btn-sm btn-outline-danger" type="submit">Löschen …</button></form>
<?php else: ?><input class="form-control form-control-sm tag-settings-readonly" aria-label="Tag <?= h($tag['name']) ?>" value="<?= h($tag['name']) ?>" readonly><span class="tag-settings-count" title="Eigene zugeordnete Dokumente"><?= h($tag['document_count']) ?> Dok.</span><button class="btn btn-sm btn-surface" type="button" disabled title="Von anderen verwendet · nur Admin">Speichern</button><button class="btn btn-sm btn-outline-danger" type="button" disabled title="Von anderen verwendet · nur Admin">Löschen …</button><?php endif ?>
</div><?php endforeach ?>
</div>
<?php if (count($tagCatalogue)===500): ?><p class="small text-secondary mt-2">Nur die ersten 500 Treffer werden angezeigt. Suche weiter eingrenzen.</p><?php endif ?>
</section>
