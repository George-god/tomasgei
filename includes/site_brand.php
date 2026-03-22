<?php
/**
 * Site brand: logo + "The Upper Realms". Include from any page.
 * Set $site_brand_compact = true before including for a smaller inline version (e.g. nav bar).
 * Asset path is relative to pages/ (../assets/).
 */
if (!isset($site_brand_compact)) {
    $site_brand_compact = false;
}
$brand_logo_src = '../assets/images/logo-upper-realms.png';
$brand_alt = 'Legacy Upper Realms — Dao Cultivation';
if ($site_brand_compact):
?>
<div class="flex items-center gap-2 flex-shrink-0">
    <img src="<?php echo htmlspecialchars($brand_logo_src, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($brand_alt, ENT_QUOTES, 'UTF-8'); ?>" class="w-10 h-10 object-contain drop-shadow-md">
    <span class="text-xl font-bold bg-gradient-to-r from-amber-200 via-yellow-300 to-amber-200 bg-clip-text text-transparent whitespace-nowrap">The Upper Realms</span>
</div>
<?php else: ?>
<div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8">
    <img src="<?php echo htmlspecialchars($brand_logo_src, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($brand_alt, ENT_QUOTES, 'UTF-8'); ?>" class="w-32 sm:w-40 h-auto drop-shadow-2xl flex-shrink-0" style="max-height: 120px; object-fit: contain;">
    <h1 class="text-3xl sm:text-4xl font-bold bg-gradient-to-r from-amber-200 via-yellow-300 to-amber-200 bg-clip-text text-transparent drop-shadow-lg text-center sm:text-left">The Upper Realms</h1>
</div>
<?php endif;
