<?php
/**
 * Appearance: the background behind the sign-in pages (log in, sign up,
 * forgot password, reset password), a solid colour or an uploaded photo.
 * Also shows whether "Continue with Google" is set up on this server, and
 * what setting it up takes. The credentials themselves are never shown or
 * entered here: they live in config/google.local.php.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/google_auth.php';

require_login('admin');

/** Largest background photo accepted, capped by what PHP itself will take. */
$maxBytes = min(8 * 1024 * 1024, ini_bytes(ini_get('upload_max_filesize')));

$settingsReady = true;
try {
    $pdo->query('SELECT 1 FROM site_settings LIMIT 1');
} catch (PDOException $e) {
    $settingsReady = false;
}

$currentType = site_setting('auth_background_type', 'colour');
$currentColour = site_setting('auth_background_colour', AUTH_BACKGROUND_DEFAULT);
$currentImage = site_setting('auth_background_image');
if (!is_site_upload_path($currentImage) || !is_file(__DIR__ . '/../' . $currentImage)) {
    $currentImage = null;
}

/** Delete a background photo this page uploaded earlier. */
$deleteImage = function ($path) {
    if (is_site_upload_path($path)) {
        @unlink(__DIR__ . '/../' . $path);
    }
};

if ($settingsReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large()) {
        flash_set('That photo is larger than this server accepts in one upload (about '
            . format_bytes(ini_bytes(ini_get('post_max_size'))) . ').', 'error');
        redirect('admin/appearance.php');
    }
    verify_csrf();

    if (($_POST['action'] ?? '') === 'reset') {
        $deleteImage($currentImage);
        save_site_settings([
            'auth_background_type' => null,
            'auth_background_colour' => null,
            'auth_background_image' => null,
        ]);
        flash_set('The sign-in background is back to the default.', 'success');
        redirect('admin/appearance.php');
    }

    $type = ($_POST['background_type'] ?? '') === 'photo' ? 'photo' : 'colour';
    $colour = strtoupper(trim((string) ($_POST['background_colour'] ?? '')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $colour)) {
        flash_set('Choose a colour in the form #RRGGBB.', 'error');
        redirect('admin/appearance.php');
    }

    $newImage = null;
    $upload = $_FILES['background_photo'] ?? null;
    $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $error = null;

        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            $error = 'That photo is larger than the ' . format_bytes($maxBytes) . ' limit.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $error = 'The photo did not upload. Please try again.';
        } elseif ($upload['size'] > $maxBytes) {
            $error = 'That photo is larger than the ' . format_bytes($maxBytes) . ' limit.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $upload['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime]) || @getimagesize($upload['tmp_name']) === false) {
                $error = 'The background must be a JPG, PNG, or WEBP image.';
            }
        }

        if ($error) {
            flash_set($error, 'error');
            redirect('admin/appearance.php');
        }

        $dir = __DIR__ . '/../' . SITE_UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            flash_set('Could not create the folder for background photos.', 'error');
            redirect('admin/appearance.php');
        }

        $newImage = SITE_UPLOAD_DIR . '/auth-bg-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($upload['tmp_name'], __DIR__ . '/../' . $newImage)) {
            flash_set('Could not save the uploaded photo.', 'error');
            redirect('admin/appearance.php');
        }
    }

    if ($type === 'photo' && !$newImage && !$currentImage) {
        flash_set('Choose a photo to upload, or pick a colour instead.', 'error');
        redirect('admin/appearance.php');
    }

    $keepImage = $newImage ?: $currentImage;
    if ($newImage && $currentImage) {
        $deleteImage($currentImage);
    }

    save_site_settings([
        'auth_background_type' => $type,
        'auth_background_colour' => $colour,
        'auth_background_image' => $keepImage,
    ]);

    flash_set('Sign-in background saved.', 'success');
    redirect('admin/appearance.php');
}

$google = [
    'enabled'  => google_enabled(),
    'redirect' => google_redirect_uri(),
    'client'   => google_config()['client_id'],
    'curl'     => function_exists('curl_init'),
    'column'   => true,
    'linked'   => 0,
];
try {
    $google['linked'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM users WHERE google_id IS NOT NULL AND deleted_at IS NULL'
    )->fetchColumn();
} catch (PDOException $e) {
    $google['column'] = false;   // migration_auth_extras.sql not run yet
}

$presets = [
    '#FAF8F3' => 'Paper',
    '#FFFFFF' => 'White',
    '#E6EFEA' => 'Leaf',
    '#184A3F' => 'Forest',
    '#1F2A28' => 'Ink',
];

$pageTitle = 'Appearance';
require __DIR__ . '/../includes/panel_head.php';
require __DIR__ . '/../includes/panel_navbar.php';
require __DIR__ . '/../includes/panel_sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold">
            <i class="fas fa-paint-brush text-primary mr-2"></i>Appearance
          </h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item active">Appearance</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
        <div class="row">
          <div class="col-lg-5">
            <?php if (!$settingsReady): ?>
            <div class="alert alert-warning">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              The <code>site_settings</code> table is missing. Import <code>database/migration_auth_extras.sql</code>,
              then reload this page.
            </div>
            <?php else: ?>
            <div class="card card-primary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-image mr-1"></i> Sign-in background
                </h3>
              </div>
              <form method="post" enctype="multipart/form-data" id="appearance-form">
                <div class="card-body">
                  <p class="text-muted small">
                    Shown behind the log in, sign up, forgot password, and reset password pages. Photos get a
                    dark green tint so the text above the form stays readable.
                  </p>
                  <?= csrf_field() ?>

                  <div class="form-group">
                    <div class="custom-control custom-radio">
                      <input type="radio" class="custom-control-input" id="type_colour" name="background_type"
                        value="colour" <?= $currentType !== 'photo' ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="type_colour">Solid colour</label>
                    </div>
                    <div class="custom-control custom-radio">
                      <input type="radio" class="custom-control-input" id="type_photo" name="background_type"
                        value="photo" <?= $currentType === 'photo' ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="type_photo">Photo</label>
                    </div>
                  </div>

                  <div class="form-group" data-when="colour">
                    <label for="background_colour">Colour</label>
                    <div class="d-flex align-items-center">
                      <input type="color" id="background_colour" name="background_colour"
                        value="<?= h($currentColour) ?>" style="width:56px; height:40px; padding:2px;"
                        class="form-control mr-2">
                      <code id="colour-code"><?= h($currentColour) ?></code>
                    </div>
                    <div class="mt-2">
                      <?php foreach ($presets as $hex => $label): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1" data-preset="<?= $hex ?>">
                          <span style="display:inline-block; width:12px; height:12px; border-radius:50%;
                            border:1px solid rgba(0,0,0,.2); background:<?= $hex ?>; vertical-align:-1px;"></span>
                          <?= h($label) ?>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <div class="form-group" data-when="photo">
                    <label for="background_photo"><?= $currentImage ? 'Replace photo' : 'Upload a photo' ?></label>
                    <?php if ($currentImage): ?>
                      <img src="<?= h(base_url($currentImage)) ?>" alt="Current sign-in background"
                        class="d-block rounded border mb-2" style="width:100%; max-height:140px; object-fit:cover;">
                    <?php endif; ?>
                    <input type="file" class="form-control-file" id="background_photo" name="background_photo"
                      accept="image/jpeg,image/png,image/webp">
                    <small class="form-text text-muted">
                      JPG, PNG, or WEBP, up to <?= format_bytes($maxBytes) ?>. A wide photo of at least 1600px
                      looks best.
                    </small>
                  </div>
                </div>
                <div class="card-footer d-flex justify-content-between">
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save
                  </button>
                  <button type="submit" name="action" value="reset" class="btn btn-outline-secondary"
                    formnovalidate onclick="return confirm('Reset the sign-in background to the default?');">
                    <i class="fas fa-undo mr-1"></i> Reset to default
                  </button>
                </div>
              </form>
            </div>
            <?php endif; ?>

            <div class="card card-outline card-secondary shadow-sm" id="google-sign-in">
              <div class="card-header d-flex align-items-center">
                <h3 class="card-title font-weight-bold d-flex align-items-center" style="gap:8px;">
                  <?= google_logo_svg(16) ?> Google sign-in
                </h3>
                <?php if ($google['enabled']): ?>
                  <span class="badge badge-success ml-auto px-2 py-1"><i class="fas fa-check mr-1"></i> On</span>
                <?php else: ?>
                  <span class="badge badge-secondary ml-auto px-2 py-1">Not set up</span>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <?php if (!$google['curl']): ?>
                  <div class="alert alert-warning small">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    PHP's <code>curl</code> extension is off, and Google sign-in needs it. Turn on
                    <code>extension=curl</code> in WAMP's PHP settings, then restart WAMP.
                  </div>
                <?php endif; ?>
                <?php if (!$google['column']): ?>
                  <div class="alert alert-warning small">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Import <code>database/migration_auth_extras.sql</code> first: accounts have nowhere to store
                    their Google link yet.
                  </div>
                <?php endif; ?>

                <?php if ($google['enabled']): ?>
                  <p class="small mb-2">
                    &ldquo;Continue with Google&rdquo; is showing on the log in and sign up pages.
                    <strong><?= $google['linked'] ?></strong> <?= $google['linked'] === 1 ? 'account is' : 'accounts are' ?>
                    connected to Google.
                  </p>
                  <p class="small text-muted mb-3">
                    <?php /* Every client ID ends in .apps.googleusercontent.com; its start is what tells them apart. */ ?>
                    Client ID starting <code><?= h(mb_substr($google['client'], 0, 16)) ?>&hellip;</code>. While the
                    Google app is in <strong>Testing</strong>, only the accounts listed under <strong>Test users</strong>
                    in Google Cloud can sign in.
                  </p>
                <?php else: ?>
                  <p class="small text-muted mb-2">
                    The &ldquo;Continue with Google&rdquo; button shows on the log in and sign up pages, but until
                    this server has Google credentials, clicking it only says Google sign-in is not set up yet.
                    Whoever owns the Google account creates them:
                  </p>
                  <ol class="small pl-3 mb-3">
                    <li>In <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud
                        console</a>, create a project and set up the <strong>OAuth consent screen</strong>
                      (External).</li>
                    <li>While it is in <strong>Testing</strong>, add every Google account that should be able to sign
                      in under <strong>Test users</strong>.</li>
                    <li>Under <strong>Credentials</strong>, create an <strong>OAuth client ID</strong> of type
                      <em>Web application</em>, with the redirect URI below.</li>
                    <li>Copy <code>config/google.local.example.php</code> to <code>config/google.local.php</code>
                      and paste in the client ID and client secret. Then reload this page.</li>
                  </ol>
                <?php endif; ?>

                <label for="google-redirect" class="small font-weight-bold mb-1">Authorized redirect URI</label>
                <div class="input-group input-group-sm">
                  <input type="text" id="google-redirect" class="form-control" value="<?= h($google['redirect']) ?>" readonly>
                  <div class="input-group-append">
                    <button type="button" class="btn btn-outline-secondary" data-copy="#google-redirect">
                      <i class="far fa-copy mr-1"></i> <span>Copy</span>
                    </button>
                  </div>
                </div>
                <small class="form-text text-muted">It must match the one in Google Cloud exactly.</small>
              </div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card card-outline card-secondary shadow-sm">
              <div class="card-header d-flex align-items-center">
                <h3 class="card-title font-weight-bold">
                  <i class="fas fa-eye mr-1"></i> Preview
                </h3>
                <a href="<?= base_url('auth/login.php?preview=1') ?>" target="_blank" rel="noopener"
                  class="btn btn-sm btn-outline-info ml-auto">
                  <i class="fas fa-external-link-alt mr-1"></i> Open login page
                </a>
              </div>
              <div class="card-body p-0">
                <iframe id="appearance-preview" src="<?= base_url('auth/login.php?preview=1') ?>"
                  title="Preview of the login page" style="width:100%; height:640px; border:0; display:block;"></iframe>
              </div>
              <div class="card-footer small text-muted">
                Changes show here as you pick them. Save to apply them for everyone.
              </div>
            </div>
          </div>
        </div>
    </div>
  </section>
</div>

<script>
  // Copy the redirect URI, for pasting into Google Cloud console.
  (function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
      btn.addEventListener('click', function () {
        var input = document.querySelector(btn.getAttribute('data-copy'));
        var label = btn.querySelector('span');
        function done(text) {
          label.textContent = text;
          setTimeout(function () { label.textContent = 'Copy'; }, 1800);
        }
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(input.value).then(function () { done('Copied'); }, function () {
            input.select();
            done('Press Ctrl+C');
          });
        } else {
          input.select();
          done(document.execCommand && document.execCommand('copy') ? 'Copied' : 'Press Ctrl+C');
        }
      });
    });
  })();

  (function () {
    var form = document.getElementById('appearance-form');
    if (!form) return;

    var colour = document.getElementById('background_colour');
    var code = document.getElementById('colour-code');
    var photo = document.getElementById('background_photo');
    var frame = document.getElementById('appearance-preview');
    var currentPhoto = <?= json_encode($currentImage ? base_url($currentImage) : null) ?>;
    var pickedPhoto = null;

    function type() {
      var checked = form.querySelector('input[name="background_type"]:checked');
      return checked ? checked.value : 'colour';
    }

    // White or forest text, whichever has more contrast: the same rule the
    // server applies in colour_prefers_light_text().
    function prefersLight(hex) {
      function ch(i) {
        var c = parseInt(hex.substr(i, 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
      }
      var l = 0.2126 * ch(1) + 0.7152 * ch(3) + 0.0722 * ch(5);
      var forest = 0.0545;
      return 1.05 / (l + 0.05) > (Math.max(l, forest) + 0.05) / (Math.min(l, forest) + 0.05);
    }

    function paint() {
      var isPhoto = type() === 'photo';
      Array.prototype.forEach.call(form.querySelectorAll('[data-when]'), function (el) {
        el.style.display = el.getAttribute('data-when') === (isPhoto ? 'photo' : 'colour') ? '' : 'none';
      });
      code.textContent = colour.value.toUpperCase();

      var doc = frame.contentDocument;
      if (!doc || !doc.body) return;
      var body = doc.body;
      var src = pickedPhoto || currentPhoto;

      if (isPhoto && src) {
        body.style.backgroundColor = '';
        body.style.backgroundImage = 'linear-gradient(rgba(15, 58, 49, .55), rgba(15, 58, 49, .72)), url("' + src + '")';
        body.classList.add('auth-page--dark');
        body.classList.remove('auth-page--light');
      } else {
        body.style.backgroundImage = 'none';
        body.style.backgroundColor = colour.value;
        var light = prefersLight(colour.value);
        body.classList.toggle('auth-page--dark', light);
        body.classList.toggle('auth-page--light', !light);
      }
    }

    Array.prototype.forEach.call(form.querySelectorAll('input[name="background_type"]'), function (radio) {
      radio.addEventListener('change', paint);
    });
    colour.addEventListener('input', paint);
    Array.prototype.forEach.call(form.querySelectorAll('[data-preset]'), function (btn) {
      btn.addEventListener('click', function () {
        colour.value = btn.getAttribute('data-preset');
        document.getElementById('type_colour').checked = true;
        paint();
      });
    });
    photo.addEventListener('change', function () {
      var file = photo.files && photo.files[0];
      if (!file) return;
      document.getElementById('type_photo').checked = true;
      var reader = new FileReader();
      reader.onload = function () {
        pickedPhoto = reader.result;
        paint();
      };
      reader.readAsDataURL(file);
    });

    frame.addEventListener('load', paint);
    paint();
  })();
</script>

<?php require __DIR__ . '/../includes/panel_footer.php'; ?>
