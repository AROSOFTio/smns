<?php
/**
 * Common Footer
 */
?>
    </div><!-- .wrapper -->
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach($additionalJS as $js): ?>
            <script src="<?php echo BASE_URL . '/assets/js/' . $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
