    </div>
    <?php require_once __DIR__ . '/ai-assistant.php'; ?>
    <div id="csToastContainer" class="toast-container position-fixed top-0 end-0 p-3" aria-live="polite" aria-atomic="true"></div>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-1.13.8/datatables.min.js"></script>
    <script src="<?php echo asset('assets/js/main.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const maintenanceUrl = <?php echo json_encode(base_url('api/background-maintenance.php'), JSON_UNESCAPED_SLASHES); ?>;
        const storageKey = 'cs_background_maintenance_last_run';
        const intervalMs = 5 * 60 * 1000;

        try {
            const lastRun = Number.parseInt(localStorage.getItem(storageKey) || '0', 10);
            const now = Date.now();
            if (Number.isFinite(lastRun) && now - lastRun < intervalMs) {
                return;
            }

            localStorage.setItem(storageKey, String(now));

            window.setTimeout(function () {
                fetch(maintenanceUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    keepalive: true
                }).catch(function () {
                    localStorage.removeItem(storageKey);
                });
            }, 1200);
        } catch (error) {
            window.setTimeout(function () {
                fetch(maintenanceUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    keepalive: true
                }).catch(function () {});
            }, 1200);
        }
    });
    </script>
    <?php if (!empty($extraScripts) && is_array($extraScripts)): ?>
        <?php foreach ($extraScripts as $scriptAsset): ?>
            <script src="<?php echo sanitize_output($scriptAsset); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
