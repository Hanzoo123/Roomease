<?php
/** Closes auth_header.php. Optional $authSwitch: the "log in / sign up" link under the card. */
$authSwitch = $authSwitch ?? null;
?>
    </div><!-- /.auth-card -->

    <?php if ($authSwitch): ?>
      <p class="auth-out">
        <?= h($authSwitch['text']) ?> <a href="<?= h($authSwitch['href']) ?>"><?= h($authSwitch['label']) ?></a>
      </p>
    <?php endif; ?>
    <p class="auth-out auth-out--back"><a href="<?= base_url('index.php') ?>">&larr; Back to RoomEase</a></p>
  </main>

  <?php require __DIR__ . '/../scripts/password_toggle.php'; ?>
</body>

</html>
