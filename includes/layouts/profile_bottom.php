<?php
/** Closes the frame opened by profile_top.php. */
if ($usePanel) {
    ?>
          </div>
        </div>
      </section>
    </div>
    <?php
    require __DIR__ . '/panel_footer.php';
} else {
    ?>
    </div>
    <?php
    require __DIR__ . '/footer.php';
}
