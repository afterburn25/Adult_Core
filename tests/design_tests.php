<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Design\DesignImages;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;
use AdultCore\Design\CutoutPlacement;
use AdultCore\Design\ThemeCatalog;
use AdultCore\UI\Layout;

$checks=0;
function designCheck(bool $truth, string $reason): void {
    global $checks;
    if (!$truth) throw new RuntimeException('Design test: ' . $reason);
    $checks++;
}

designCheck(count(ThemeCatalog::THEMES) === 4,'Four distinct layout presets');
designCheck(array_keys(ThemeCatalog::THEMES) === ['noir-gold','velvet-plum','midnight-teal','rose-ivory'],'Original four keys unchanged, without duplicates');
designCheck(DesignSchema::VERSION==='m11_visual_design_v1','Original design schema remains stable');
designCheck(CutoutPlacement::VERSION==='m12_cutout_placement_v1','Additive placement schema');
designCheck(HighlightSchema::VERSION==='m18_home_highlights_v1','Additive safe homepage cards schema');
designCheck(count(HighlightSchema::defaults())===3,'Three safe default homepage info cards');
$cards=HighlightSchema::validate([
    ['title'=>'Only <welcome>','body'=>'Safe & plain informational text'],
    ['title'=>'Local <owners>','body'=>'Independent website design'],
    ['title'=>'Members','body'=>'No automatic media grant'],
]);
$rendered=Layout::site('Welcome','Text',[], 'Local Studio',true,['highlights'=>$cards]);
designCheck(str_contains($rendered,'Only &lt;welcome&gt;')
    && str_contains($rendered,'Safe &amp; plain')
    && !str_contains($rendered,'Only <welcome>'),'Homepage cards are always escaped');
foreach ([
    [['title'=>'Only one','body'=>'Missing two']],
    [['title'=>'','body'=>'Valid'],['title'=>'A','body'=>'B'],['title'=>'C','body'=>'D']],
    [['title'=>"Line\nBreak",'body'=>'Valid'],['title'=>'A','body'=>'B'],['title'=>'C','body'=>'D']],
    [['title'=>'Ending'."\0",'body'=>'Valid'],['title'=>'A','body'=>'B'],['title'=>'C','body'=>'D']],
    [['title'=>'Valid','body'=>'Ending'."\0"],['title'=>'A','body'=>'B'],['title'=>'C','body'=>'D']],
] as $invalidCards) {
    try {
        HighlightSchema::validate($invalidCards);
        throw new RuntimeException('Unsafe home cards accepted.');
    } catch (RuntimeException $e) {
        designCheck($e->getMessage()!=='Unsafe home cards accepted.','Invalid cards rejected');
    }
}
designCheck(CutoutPlacement::defaults() === ['cutout_x'=>50,'cutout_y'=>50,'cutout_width'=>80], 'Safe default placement values');
foreach ([['0','0','20'],['100','100','100'],['23','68','62']] as $coords) {
    $value=CutoutPlacement::validate(...$coords);
    designCheck($value['cutout_x']===(int)$coords[0] && $value['cutout_width']===(int)$coords[2], 'Valid cutout placement accepted');
}
foreach ([['-1','50','80'],['50','101','80'],['50','50','19'],['50','50','101'],
    ['50px','50','80'],['50','50','80%;body{display:none}'],['1e2','50','80'],['50','50','80.1'],['00','50','80']] as $coords) {
    try { CutoutPlacement::validate(...$coords); throw new RuntimeException('Unsafe cutout placement accepted.'); }
    catch (RuntimeException $e) { designCheck($e->getMessage() !== 'Unsafe cutout placement accepted.', 'Invalid coordinate or CSS injection refused'); }
}
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
designCheck(str_contains($html,'class="cutout-positioner"'),'Published frontend wraps cutout for live placement');
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
// Public decorative assets must not retain private location/creator notes,
// even when the administrator initially keeps them in private preview.
$cleanPng=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGO4p2gOAAMXATf6OqxSAAAAAElFTkSuQmCC',true);
if (!is_string($cleanPng)) throw new RuntimeException('Disposable design PNG missing.');
file_put_contents($test, $cleanPng);
designCheck(DesignImages::inspect($test)['mime']==='image/png','Metadata-free design PNG accepted');
$metadata='GPSComment=private';
$tag='tEXt';
$chunk=pack('N',strlen($metadata)).$tag.$metadata.hex2bin(hash('crc32b',$tag.$metadata));
file_put_contents($test, substr($cleanPng,0,33).$chunk.substr($cleanPng,33));
try { DesignImages::inspect($test); throw new RuntimeException('Design PNG metadata accepted.'); }
catch (RuntimeException $e) { designCheck($e->getMessage() !== 'Design PNG metadata accepted.','Design studio refuses private PNG metadata'); }
// Legacy assets uploaded before intake screening are blocked on HTTP delivery
// rather than trusting an old "public safe" database flag.
$cleanStored = tempnam(sys_get_temp_dir(), 'adult-design-delivery-');
if ($cleanStored === false) throw new RuntimeException('Missing delivery fixture path.');
file_put_contents($cleanStored, $cleanPng);
designCheck(DesignImages::safeToServe($cleanStored,'image/png',strlen($cleanPng)),
    'Metadata-free legacy PNG can still be delivered');
designCheck(!DesignImages::safeToServe($cleanStored,'image/jpeg',strlen($cleanPng)),
    'Legacy asset cannot be served with forged MIME');
designCheck(!DesignImages::safeToServe($cleanStored,'image/png',strlen($cleanPng)+1),
    'Legacy asset cannot bypass stored byte length');
file_put_contents($cleanStored, substr($cleanPng,0,33).$chunk.substr($cleanPng,33));
designCheck(!DesignImages::safeToServe($cleanStored,'image/png',strlen($cleanPng)+strlen($chunk)),
    'Legacy location/text-bearing PNG is denied at serve time');
unlink($cleanStored);
unlink($test);
$css=file_get_contents(dirname(__DIR__).'/public/assets/style.css');
designCheck(is_string($css)&&str_contains($css,'.theme-rose-ivory')&&str_contains($css,'.theme-velvet-plum'),'Built-in layout CSS exists');
$js=file_get_contents(dirname(__DIR__).'/public/assets/design-editor.js');
designCheck(is_string($js)&&str_contains($js,'field.value')&&str_contains($js,'node.innerText'),'Live text input sync uses plain text');
designCheck(str_contains($js,'preview-highlight-')&&str_contains($js,'preview.textContent=field.value'),
    'Homepage information-card preview syncs as inert text, never executable HTML');
designCheck(str_contains($js,'setPointerCapture') && str_contains($js,'pointermove') && str_contains($js,'pointercancel'), 'Pointer capture supports dragging and cancel');
designCheck(str_contains($js,'cutout-resize-handle') && str_contains($js,'cutout-move-handle') && str_contains($js,'keydown'), 'Visible resize/drag handles and keyboard support');
designCheck(str_contains($js,'--cutout-x') && str_contains($js,'--cutout-y') && str_contains($js,'--cutout-width'), 'Preview uses the same CSS variables as public renderer');
designCheck(str_contains($js, 'Add your cutout photo') && str_contains($css, 'studio-preview-placeholder'), 'Empty hero prompts for user cutout without adding stock photos');
$source = file_get_contents(dirname(__DIR__) . '/src/Http/DesignController.php');
designCheck(str_contains((string)$source, 'No cutout — keep theme background'), 'No-cutout option preserves preset styling');
designCheck(str_contains((string)$source,'cutout-placement-controls') && str_contains((string)$source,'cutout_width'), 'Form declares explicit editor placement controls');
designCheck(str_contains((string)$source,'PageHistorySchema::installed'),
    'Design Studio refuses writes before transactional private page-history migration');
$studioCSS=(string)file_get_contents(dirname(__DIR__).'/public/assets/style.css');
designCheck(str_contains($studioCSS,'.cutout-positioner') && str_contains($studioCSS,'touch-action:none'), 'Cutout image placement is styled for touch input');
echo "Passed $checks visual-design safety checks.\n";
