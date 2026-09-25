<form method="post" class="language-picker o8-info-group o8-info-group--soft mb-3<?= !empty($languagePickerInline)?' d-flex align-items-end gap-2':'' ?>">
<?php postFields('language_save',$actor?'account':''); ?>
<?php if (!empty($languagePickerInline)): ?>
<label class="visually-hidden" for="accountLanguageSelect"><?= h(tr('common.language')) ?></label>
<select class="form-select flex-grow-1" id="accountLanguageSelect" name="language">
<?php foreach ($languageCatalog as $item): ?><option value="<?= h($item['code']) ?>" <?= $language===$item['code']?'selected':'' ?>><?= h($item['name']) ?></option><?php endforeach ?>
</select>
<button class="btn btn-surface" type="submit"><?= h(tr('common.save')) ?></button>
<?php else: ?>
<label class="form-label d-block mb-2"><?= h(tr('common.language')) ?>
<select class="form-select" name="language">
<?php foreach ($languageCatalog as $item): ?><option value="<?= h($item['code']) ?>" <?= $language===$item['code']?'selected':'' ?>><?= h($item['name']) ?></option><?php endforeach ?>
</select></label>
<button class="btn btn-surface" type="submit"><?= h(tr('common.save')) ?></button>
<?php endif ?>
</form>
