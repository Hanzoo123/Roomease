<?php
/**
 * Closes the standalone sign-in layout opened by auth_header.php.
 *
 * Set before including:
 *   $authSwitch  optional ['text' => ..., 'href' => ..., 'label' => ...], the
 *                line under the card that moves between log in and sign up
 */
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

  <?php require __DIR__ . '/password_toggle.php'; ?>
</body>

</html>
