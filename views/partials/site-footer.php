<?php
/**
 * The one site footer (Home Page design) used by every page: public, Applicant, MSWD Staff and Admin.
 * Rendered by includes/footer.php and, for the applicant dashboard, by views/applicant/layout.php.
 *
 * @var string $footerAbout      short text under the VIRAC CATANDUANES brand
 * @var string $footerListTitle  heading of the second column (e.g. "ONLINE SERVICES", "APPLICANT PORTAL")
 * @var array  $footerList       [label, url|null] pairs; null renders plain text (no page exists for it)
 * @var bool   $footerGov        true: the long Republic of the Philippines block instead of the bottom bar
 */
$footerList = $footerList ?? [];
$footerGov = $footerGov ?? false;
$footerAddress = setting('office_address');
$footerEmail = setting('office_email');
$footerPhone = setting('office_phone');
?>
<footer class="site-footer">
  <div>
    <div class="footer-brand">VIRAC<br>CATANDUANES</div>
    <div class="footer-small"><?= e($footerAbout) ?></div>
  </div>
  <nav aria-label="<?= e(ucwords(strtolower($footerListTitle))) ?>">
    <div class="footer-heading"><?= e($footerListTitle) ?></div>
    <ul class="footer-small footer-list">
      <?php foreach ($footerList as [$label, $url]): ?>
        <li><?php if ($url !== null): ?><a href="<?= e($url) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <div>
    <div class="footer-heading">DIRECTORY</div>
    <ul class="footer-small footer-list"><li>Office of the Municipal Mayor</li><li>MSWDO-Virac</li><li>MDRRMO-Virac</li></ul>
  </div>
  <div>
    <div class="footer-heading">CONTACT DETAILS</div>
    <div class="footer-contact">
      <?php if ($footerAddress !== ''): ?><?= e($footerAddress) ?><br><?php endif; ?>
      <?php if ($footerEmail !== ''): ?><a href="mailto:<?= e($footerEmail) ?>"><?= e($footerEmail) ?></a><br><?php endif; ?>
      <?php if ($footerPhone !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $footerPhone)) ?>"><?= e($footerPhone) ?></a><?php endif; ?>
    </div>
    <div class="footer-logos">
      <img src="<?= asset('images/transparency-seal.png') ?>" alt="Philippine Transparency Seal" class="footer-seal">
      <img src="<?= asset('images/bagong-pilipinas-logo.png') ?>" alt="Bagong Pilipinas" class="footer-bp">
    </div>
  </div>
</footer>

<?php if ($footerGov): ?>
<div class="gov-footer">
  <div></div>
  <div>
    <div class="gov-heading">REPUBLIC OF THE PHILIPPINES</div>
    <div class="gov-small">All content is in the public domain unless otherwise stated.</div>
  </div>
  <div class="gov-cols">
    <div>
      <div class="gov-heading">ABOUT GOVPH</div>
      <div class="gov-small" style="margin-bottom:10px">Learn more about the Philippine government, its structure, how government works and the people behind it.</div>
      <div class="gov-small gov-links">GOV.PH<br>Open Data Portal<br>Official Gazette</div>
    </div>
    <div>
      <div class="gov-heading">GOVERNMENT LINKS</div>
      <div class="gov-small gov-links">Office of the President<br>Office of the Vice President<br>Senate of the Philippines<br>House of Representatives<br>Supreme Court<br>Court of Appeals<br>Sandiganbayan</div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="footer-bottom-bar">
  <p>All content is in the public domain unless otherwise stated. · Republic of the Philippines</p>
  <p>&copy; <?= date('Y') ?> Municipal Social Welfare and Development Office (MSWDO), Virac, Catanduanes</p>
  <p class="footer-privacy">Data Privacy: personal information is handled according to the Data Privacy Act of 2012 (RA 10173).</p>
</div>
<?php endif; ?>
