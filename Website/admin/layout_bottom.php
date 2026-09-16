        </div><!-- /content -->
        <footer class="foot">
            <?php echo e(ATT_NAME); ?> v<?php echo e(ATT_VERSION); ?> ·
            <?php echo e(date('D, d M Y H:i')); ?> (Asia/Qatar)
        </footer>
    </main>
</div>
<?php if (!empty($needsMap)): ?>
<script src="<?php echo ATT_ASSETS_URL; ?>/vendor/leaflet/leaflet.js"></script>
<script src="<?php echo ATT_ASSETS_URL; ?>/vendor/leaflet-draw/leaflet.draw.js"></script>
<script src="<?php echo ATT_ASSETS_URL; ?>/map.js?v=<?php echo ATT_VERSION; ?>"></script>
<?php endif; ?>
<script src="<?php echo ATT_ASSETS_URL; ?>/admin.js?v=<?php echo ATT_VERSION; ?>"></script>
<?php echo $footExtra ?? ''; ?>
</body>
</html>
