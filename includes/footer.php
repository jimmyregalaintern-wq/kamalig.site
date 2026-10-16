    <?php if (($pageClass ?? '') === 'landing-page'): ?>
        <script src="<?php echo htmlspecialchars(url('assets/landing.js')); ?>" defer></script>
        </body>
        </html>
    <?php else: ?>
                </section>
            </main>
        </div>
        <?php if (is_logged_in()): ?>
            <?php include __DIR__ . '/assistant_widget.php'; ?>
        <?php endif; ?>
        <script src="<?php echo htmlspecialchars(url('assets/dashboard.js')); ?>" defer></script>
        <script src="<?php echo htmlspecialchars(url('assets/voice-input.js')); ?>" defer></script>
        <script src="<?php echo htmlspecialchars(url('assets/assistant.js')); ?>" defer></script>
        <?php if (is_logged_in()): ?>
            <script src="<?php echo htmlspecialchars(url('assets/notifications-live.js')); ?>" defer></script>
        <?php endif; ?>
    </body>
    </html>
    <?php endif; ?>
