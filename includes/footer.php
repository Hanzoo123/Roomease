  </main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-inner">
      <div class="footer-brand">
        <a href="<?= base_url('index.php') ?>" class="brand">RoomEase</a>
        <p>Boarding houses and rooms for rent in Baybay City, Leyte.</p>
      </div>

      <nav class="footer-links" aria-label="Footer">
        <div>
          <h2>Renters</h2>
          <ul>
            <li><a href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a></li>
            <li><a href="<?= base_url('boarder/saved.php') ?>">Saved rooms</a></li>
          </ul>
        </div>
        <div>
          <h2>Landlords</h2>
          <ul>
            <?php if (current_role() === 'landlord'): ?>
              <li><a href="<?= base_url('landlord/add_listing.php') ?>">Add a listing</a></li>
              <li><a href="<?= base_url('landlord/dashboard.php') ?>">My listings</a></li>
            <?php else: ?>
              <li><a href="<?= base_url('auth/register.php?role=landlord') ?>">List a property</a></li>
              <li><a href="<?= base_url('landlord/dashboard.php') ?>">My listings</a></li>
            <?php endif; ?>
          </ul>
        </div>
        <div>
          <h2>Account</h2>
          <ul>
            <?php if (is_logged_in()): ?>
              <li><a href="<?= base_url('auth/profile.php') ?>">Profile</a></li>
              <li><a href="<?= base_url('auth/logout.php') ?>">Log out</a></li>
            <?php else: ?>
              <li><a href="<?= base_url('auth/login.php') ?>">Log in</a></li>
              <li><a href="<?= base_url('auth/register.php') ?>">Sign up</a></li>
            <?php endif; ?>
          </ul>
        </div>
      </nav>
    </div>

    <div class="footer-base">&copy; <?= date('Y') ?> RoomEase &middot; A web-based boarding house information and listing system</div>
  </div>
</footer>

<?php require __DIR__ . '/password_toggle.php'; ?>
<?php require __DIR__ . '/favorite_toggle.php'; ?>
<?php require __DIR__ . '/copy_number.php'; ?>

</body>

</html>
