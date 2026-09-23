<h3 class="h4">Mandant auswählen</h3>
<?php if (!$choices): ?><div class="alert alert-info">Für Ihr Konto ist derzeit kein aktiver Mandant freigegeben. Wenden Sie sich an Ihren Administrator oder nehmen Sie unter „Mein Konto“ eine Einladung an.</div>
<?php else: ?><p>Nur Ihre freigegebenen Mandanten sind hier sichtbar.</p>
<?php foreach ($choices as $choice): ?><form method="post" class="tenant-card" data-tenant-switch><?php postFields('tenant_switch',''); ?><input type="hidden" name="tenant_id" value="<?= h($choice['tenant_id']) ?>"><h4 class="h5"><?= h($choice['name']) ?></h4><p>Ihre Rolle: <?= h($choice['role']) ?></p><button class="btn btn-primary">Mandant öffnen</button></form><?php endforeach ?>
<?php endif ?>
