<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Design\DesignImages;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\ThemeCatalog;
use AdultCore\UI\Layout;

$checks=0;
function designCheck(bool $truth, string $reason): void {
    global $checks;
    if (!$truth) throw new RuntimeException('Design test: ' . $reason);
    $checks++;
}

designCheck(count(ThemeCatalog::THEMES) === 4,'Four distinct layout presets');
designCheck(DesignSchema::VERSION==='m11_visual_design_v1','Separate additive migration');
foreach (ThemeCatalog::THEMES as $key=>$theme) {
    ThemeCatalog::validate($key,$theme['accent'],'right','large');
    designCheck(true,'Theme preset accepted');
    designCheck(count($theme['swatches'])===3,'Each theme declares palette');
}
foreach ([['evil','#ffffff','right','large'], ['noir-gold','red','right','large'],
        ['noir-gold','#fff;body{display:none}','right','large'],
        ['noir-gold','#d9ae75','center','large'],
        ['noir-gold','#d9ae75','right','huge']] as $data) {
    try { ThemeCatalog::validate(...$data); throw new RuntimeException('Rejected unsafe design was accepted.'); }
    catch (RuntimeException $e) { designCheck($e->getMessage() !== 'Rejected unsafe design was accepted.','Unsafe theme rejected'); }
}
$design=['theme_key'=>'midnight-teal','accent_color'=>'#ffffff','image_side'=>'left','image_size'=>'medium','public_asset_id'=>12];
$html = Layout::site('A <title>','B & body',[], 'Cutout Studio',true,$design);
designCheck(str_contains($html,'theme-midnight-teal image-left image-size-medium'),'Layout receives safe preset classes');
designCheck(str_contains($html,'src="/design/asset/12"'),'Public-approved image uses checked dynamic route');
designCheck(str_contains($html,'&lt;title&gt;')&&!str_contains($html,'<title>A <title>'),'Visual text remains HTML-escaped');
designCheck(str_contains($html,'/design.css'),'Validated CSS route included');
designCheck(str_contains(Layout::admin('Studio','Body','/admin/design'), 'href="/admin/design" aria-current="page"'), 'Admin studio navigation active');
$bad = Layout::site('ok','body',[], 'Good',true,['theme_key'=>'evil" onclick="alert(1)','image_side'=>'center','image_size'=>'xxx','public_asset_id'=>0]);
designCheck(str_contains($bad,'theme-noir-gold image-right image-size-large'),'Unsafe theme input falls back');
$test = tempnam(sys_get_temp_dir(),'ac-design-test-');
file_put_contents($test, 'not an image');
try { DesignImages::inspect($test); throw new RuntimeException('Fake image accepted.'); }
catch (RuntimeException $e) { designCheck($e->getMessage() !== 'Fake image accepted.','Reject non-image'); }
file_put_contents($test,str_repeat('a',DesignImages::MAX_BYTES+1));
try { DesignImages::inspect($test); throw new RuntimeException('Large image accepted.'); }
catch (RuntimeException $e) { designCheck($e->getMessage() !== 'Large image accepted.','Reject oversized upload'); }
unlink($test);
$css=file_get_contents(dirname(__DIR__).'/public/assets/style.css');
designCheck(is_string($css)&&str_contains($css,'.theme-rose-ivory')&&str_contains($css,'.theme-velvet-plum'),'Built-in layout CSS exists');
$js=file_get_contents(dirname(__DIR__).'/public/assets/design-editor.js');
designCheck(is_string($js)&&str_contains($js,'contenteditable')&&str_contains($js,'field.value'),'Live text input sync uses plain text');
echo "Passed $checks visual-design safety checks.\n";
