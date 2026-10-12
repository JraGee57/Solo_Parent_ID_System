<?php /** @var string $message */ ?>
<div class="border rounded p-4" style="background:var(--surface);font-size:14px;color:var(--body-text)">
  <p>Log in or create an account <?= e($message) ?></p>
  <p>Complete your personal and child information, tick the documents you have available, then review and submit. MSWDO handles document verification, interviews, and ID claiming instructions.</p>
  <div class="home-actions">
    <a class="btn-navy text-decoration-none" href="<?= base_url('login.php?next=apply') ?>">Log In</a>
    <a class="btn-outline text-decoration-none" href="<?= base_url('register.php?next=apply') ?>">Create Account</a>
  </div>
</div>
