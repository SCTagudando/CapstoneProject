<?php
// images/cottage_placeholder.php
// Generates a styled SVG placeholder image for a cottage
// Usage: cottage_placeholder.php?type=bahay_kubo&name=Bahay+Kubo+1&n=1

$type = $_GET['type'] ?? 'bahay_kubo';
$name = $_GET['name'] ?? 'Cottage';
$n    = (int)($_GET['n'] ?? 1);

// Color palettes per type
$palettes = [
    'bahay_kubo'   => ['#2d6a4f','#40916c','#74c69d','#d8f3dc','#c9a84c'],
    'open_cottage' => ['#8b5e3c','#a0522d','#cd853f','#f5deb3','#2d6a4f'],
    'kubo_premium' => ['#1a3a2e','#2d6a4f','#c9a84c','#f4d58d','#ffffff'],
];

$pal = $palettes[$type] ?? $palettes['bahay_kubo'];
[$bg1, $bg2, $accent, $light, $text] = $pal;

// Different scene per number
$scenes = [
    1 => 'hut',
    2 => 'trees',
    3 => 'pavilion',
    4 => 'pool',
    5 => 'garden',
];
$scene = $scenes[($n % 5) ?: 5];

header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');

$label = match($type) {
    'bahay_kubo'   => 'Bahay Kubo',
    'open_cottage' => 'Open Cottage',
    'kubo_premium' => 'Kubo Premium',
    default        => 'Cottage',
};
?>
<svg xmlns="http://www.w3.org/2000/svg" width="600" height="380" viewBox="0 0 600 380">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:<?= $bg1 ?>"/>
      <stop offset="100%" style="stop-color:<?= $bg2 ?>"/>
    </linearGradient>
    <linearGradient id="sky" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#87CEEB;stop-opacity:0.3"/>
      <stop offset="100%" style="stop-color:<?= $bg2 ?>;stop-opacity:0"/>
    </linearGradient>
  </defs>

  <!-- Background -->
  <rect width="600" height="380" fill="url(#bg)"/>
  <rect width="600" height="380" fill="url(#sky)"/>

  <!-- Ground -->
  <ellipse cx="300" cy="360" rx="280" ry="40" fill="<?= $bg2 ?>" opacity="0.5"/>

  <?php if ($scene === 'hut' || $type === 'bahay_kubo'): ?>
  <!-- Bamboo Kubo Scene -->
  <!-- Main hut body -->
  <rect x="190" y="200" width="220" height="140" rx="4" fill="#8B5E3C"/>
  <!-- Roof -->
  <polygon points="150,200 300,100 450,200" fill="<?= $accent ?>"/>
  <polygon points="160,200 300,108 440,200" fill="<?= $bg1 ?>" opacity="0.3"/>
  <!-- Door -->
  <rect x="272" y="270" width="56" height="70" rx="4" fill="#5C3317"/>
  <!-- Windows -->
  <rect x="200" y="220" width="50" height="40" rx="4" fill="#87CEEB" opacity="0.6"/>
  <rect x="350" y="220" width="50" height="40" rx="4" fill="#87CEEB" opacity="0.6"/>
  <!-- Stilts -->
  <rect x="210" y="330" width="12" height="30" fill="#5C3317"/>
  <rect x="378" y="330" width="12" height="30" fill="#5C3317"/>
  <!-- Bamboo poles -->
  <rect x="185" y="195" width="8" height="150" rx="2" fill="#6B8E23" opacity="0.7"/>
  <rect x="407" y="195" width="8" height="150" rx="2" fill="#6B8E23" opacity="0.7"/>
  <!-- Palm trees -->
  <rect x="80" y="180" width="10" height="160" rx="3" fill="#5C3317"/>
  <ellipse cx="85" cy="175" rx="45" ry="35" fill="#2d6a4f"/>
  <ellipse cx="65" cy="165" rx="30" ry="22" fill="#40916c"/>
  <rect x="500" y="200" width="10" height="140" rx="3" fill="#5C3317"/>
  <ellipse cx="505" cy="195" rx="40" ry="30" fill="#2d6a4f"/>
  <!-- Flowers -->
  <circle cx="170" cy="340" r="8" fill="<?= $light ?>"/>
  <circle cx="185" cy="335" r="6" fill="<?= $accent ?>"/>
  <circle cx="420" cy="340" r="8" fill="<?= $light ?>"/>
  <circle cx="435" cy="335" r="6" fill="<?= $accent ?>"/>

  <?php elseif ($scene === 'pavilion' || $type === 'open_cottage'): ?>
  <!-- Open Pavilion Scene -->
  <!-- Roof -->
  <polygon points="100,170 300,80 500,170" fill="<?= $accent ?>"/>
  <rect x="100" y="168" width="400" height="8" fill="<?= $bg1 ?>"/>
  <!-- Pillars -->
  <rect x="115" y="175" width="16" height="165" rx="4" fill="#8B5E3C"/>
  <rect x="208" y="175" width="16" height="165" rx="4" fill="#8B5E3C"/>
  <rect x="376" y="175" width="16" height="165" rx="4" fill="#8B5E3C"/>
  <rect x="469" y="175" width="16" height="165" rx="4" fill="#8B5E3C"/>
  <!-- Tables -->
  <rect x="150" y="280" width="120" height="10" rx="3" fill="#CD853F"/>
  <rect x="165" y="290" width="8" height="50" fill="#A0522D"/>
  <rect x="253" y="290" width="8" height="50" fill="#A0522D"/>
  <rect x="330" y="280" width="120" height="10" rx="3" fill="#CD853F"/>
  <rect x="345" y="290" width="8" height="50" fill="#A0522D"/>
  <rect x="433" y="290" width="8" height="50" fill="#A0522D"/>
  <!-- String lights -->
  <line x1="115" y1="185" x2="485" y2="185" stroke="<?= $light ?>" stroke-width="1.5"/>
  <?php for($i=0;$i<8;$i++): ?>
  <circle cx="<?= 130 + $i*48 ?>" cy="<?= 185 + ($i%2)*8 ?>" r="4" fill="<?= $accent ?>" opacity="0.9"/>
  <?php endfor; ?>
  <!-- Tropical plants -->
  <rect x="60" y="240" width="8" height="100" rx="2" fill="#5C3317"/>
  <ellipse cx="64" cy="235" rx="35" ry="28" fill="#2d6a4f"/>
  <rect x="525" y="240" width="8" height="100" rx="2" fill="#5C3317"/>
  <ellipse cx="529" cy="235" rx="35" ry="28" fill="#2d6a4f"/>
  <!-- Banner -->
  <rect x="180" y="100" width="240" height="36" rx="6" fill="<?= $bg1 ?>" opacity="0.7"/>
  <text x="300" y="124" font-family="serif" font-size="14" fill="<?= $light ?>" text-anchor="middle">Events · Weddings · Parties</text>

  <?php else: ?>
  <!-- Premium Kubo Scene -->
  <!-- Main structure -->
  <rect x="160" y="180" width="280" height="170" rx="8" fill="#3D2B1F"/>
  <!-- Elegant roof -->
  <polygon points="130,185 300,85 470,185" fill="<?= $accent ?>"/>
  <polygon points="140,185 300,93 460,185" fill="<?= $bg1 ?>" opacity="0.25"/>
  <!-- Gold trim -->
  <line x1="130" y1="185" x2="470" y2="185" stroke="<?= $accent ?>" stroke-width="3"/>
  <!-- Large windows -->
  <rect x="175" y="200" width="90" height="65" rx="6" fill="#87CEEB" opacity="0.5"/>
  <rect x="335" y="200" width="90" height="65" rx="6" fill="#87CEEB" opacity="0.5"/>
  <line x1="220" y1="200" x2="220" y2="265" stroke="#fff" stroke-width="1" opacity="0.4"/>
  <line x1="175" y1="232" x2="265" y2="232" stroke="#fff" stroke-width="1" opacity="0.4"/>
  <line x1="380" y1="200" x2="380" y2="265" stroke="#fff" stroke-width="1" opacity="0.4"/>
  <line x1="335" y1="232" x2="425" y2="232" stroke="#fff" stroke-width="1" opacity="0.4"/>
  <!-- Door -->
  <rect x="263" y="265" width="74" height="85" rx="6" fill="#2A1A0E"/>
  <circle cx="325" cy="307" r="4" fill="<?= $accent ?>"/>
  <!-- AC unit -->
  <rect x="380" y="183" width="60" height="18" rx="3" fill="#d0d0d0"/>
  <!-- Veranda -->
  <rect x="140" y="345" width="320" height="10" rx="3" fill="<?= $accent ?>" opacity="0.6"/>
  <!-- Luxury garden -->
  <rect x="65" y="210" width="10" height="140" rx="3" fill="#5C3317"/>
  <ellipse cx="70" cy="205" rx="42" ry="32" fill="#1a3a2e"/>
  <ellipse cx="55" cy="198" rx="28" ry="20" fill="#2d6a4f"/>
  <rect x="515" y="210" width="10" height="140" rx="3" fill="#5C3317"/>
  <ellipse cx="520" cy="205" rx="42" ry="32" fill="#1a3a2e"/>
  <ellipse cx="535" cy="198" rx="28" ry="20" fill="#2d6a4f"/>
  <!-- Stars/lights -->
  <?php for($i=0;$i<6;$i++): ?>
  <circle cx="<?= 160 + $i*56 ?>" cy="170" r="2.5" fill="<?= $accent ?>" opacity="0.8"/>
  <?php endfor; ?>
  <?php endif; ?>

  <!-- Overlay tint for branding -->
  <rect width="600" height="380" fill="<?= $bg1 ?>" opacity="0.15"/>

  <!-- Label pill -->
  <rect x="16" y="16" width="<?= strlen($label)*8 + 20 ?>" height="28" rx="14" fill="<?= $bg1 ?>" opacity="0.85"/>
  <text x="26" y="34" font-family="sans-serif" font-size="12" font-weight="700" fill="<?= $light ?>" letter-spacing="1"><?= htmlspecialchars($label) ?></text>

  <!-- "Replace photo" watermark -->
  <rect x="0" y="340" width="600" height="40" fill="rgba(0,0,0,0.35)"/>
  <text x="300" y="366" font-family="sans-serif" font-size="13" fill="rgba(255,255,255,0.7)" text-anchor="middle">📷 Sample image — replace with actual photo</text>
</svg>
