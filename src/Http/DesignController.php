<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Design\DesignImages;
use AdultCore\Design\DesignRepository;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;
use AdultCore\Design\CutoutPlacement;
use AdultCore\Design\ThemeCatalog;
use AdultCore\UI\Layout;
use AdultCore\Website\PageRepository;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\PageHistorySchema;
use PDO;
use RuntimeException;
use Throwable;

/** Visual homepage editor; can never bypass the admin gate or publish protected media. */
final class DesignController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $path, string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'site.settings.manage')) {
            $this->render(403, 'Access denied', '<p>Not authorized.</p>'); return;
        }
        $imageRoute = preg_match('#^/admin/design/asset/([1-9][0-9]{0,18})$#D', $path, $matches) === 1;
        if ($imageRoute) {
            if (!in_array($method, ['GET','HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); return; }
            try {
                $pdo = Connection::open($this->root);
                $asset = (new DesignRepository($pdo))->asset((int)$matches[1]);
                if ($asset === null) { http_response_code(404); return; }
                (new DesignImages($this->root, $pdo))->serve($asset);
            } catch (Throwable) { http_response_code(503); }
            return;
        }
        if (!in_array($path, ['/admin/design','/admin/design/upload'], true)) { http_response_code(404); return; }
        if (($path === '/admin/design/upload' && $method !== 'POST')
            || ($path === '/admin/design' && !in_array($method,['GET','POST'],true))) {
            header('Allow: ' . ($path === '/admin/design' ? 'GET, POST' : 'POST'));
            http_response_code(405); return;
        }
        if ($method === 'POST' && !AdminSession::csrfValid($_POST['csrf'] ?? null)) {
            $this->render(403,'Security token expired','<p>Reload the studio and try again.</p>'); return;
        }
        try {
            $pdo = Connection::open($this->root);
            $m = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $m->execute([DesignSchema::VERSION]);
            if ((int)$m->fetchColumn() === 0) {
                $this->render(503, 'Setup required', '<p>Apply the additive design schema from <a href="/admin/setup">Site setup</a> before editing.</p>'); return;
            }
            if (!CutoutPlacement::installed($pdo)) {
                $this->render(503, 'Setup required', '<p>Back up the database, then apply the additive cutout-placement migration from <a href="/admin/setup">Site setup</a> before editing.</p>'); return;
            }
            if (!PageRevisionSchema::installed($pdo)
                || !PageNavigationSchema::installed($pdo)) {
                $this->render(503,'Page editor setup required',
                    '<p>Back up the database, then apply the additive page editor migrations in <a href="/admin/setup">Site setup</a> before editing.</p>');
                return;
            }
            if (!HighlightSchema::installed($pdo)) {
                $this->render(503,'Homepage card setup required',
                    '<p>Back up the database, then apply the additive homepage cards migration in <a href="/admin/setup">Site setup</a> before editing.</p>');
                return;
            }
            if (!PageHistorySchema::installed($pdo)) {
                $this->render(503,'Page history setup required',
                    '<p>Back up the database, then apply the additive private page history migration in <a href="/admin/setup">Site setup</a> before editing.</p>');
                return;
            }
            $repo = new DesignRepository($pdo);
            if ($method === 'POST') {
                if ($path === '/admin/design/upload') {
                    $public = ($_POST['public_use_confirm'] ?? null) === '1';
                    if (!$public && ($_POST['public_use_confirm'] ?? null) !== null) {
                        throw new RuntimeException('Invalid public-use confirmation.');
                    }
                    (new DesignImages($this->root, $pdo))->upload($_FILES['design_image'] ?? [], $public);
                    header('Location: /admin/design?result=uploaded',true,303); return;
                }
                $this->save($pdo,$repo);
                header('Location: /admin/design?result=saved',true,303); return;
            }
            $this->studio($pdo,$repo);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'The design studio is temporarily unavailable.';
            $this->render(422, 'Design action unsuccessful', '<p role="alert">' . self::e($message) . '</p>');
        }
    }

    private function save(PDO $pdo, DesignRepository $repo): void
    {
        $theme = $_POST['theme'] ?? null;
        $accent = $_POST['accent'] ?? null;
        $side = $_POST['image_side'] ?? null;
        $size = $_POST['image_size'] ?? null;
        $title = $_POST['title'] ?? null;
        $body = $_POST['body'] ?? null;
        $choice = $_POST['hero_asset'] ?? null;
        $placement = CutoutPlacement::validate($_POST['cutout_x'] ?? null, $_POST['cutout_y'] ?? null, $_POST['cutout_width'] ?? null);
        if (!is_string($theme) || !is_string($accent) || !is_string($side) || !is_string($size)
            || !is_string($title) || !is_string($body) || !is_string($choice)) {
            throw new RuntimeException('Invalid design settings.');
        }
        ThemeCatalog::validate($theme,$accent,$side,$size);
        $hasCards=false;
        $fields=0;
        $cards=[];
        for ($i=1;$i<=3;$i++) {
            $cardTitle=$_POST['highlight_'.$i.'_title']??null;
            $cardBody=$_POST['highlight_'.$i.'_body']??null;
            if ($cardTitle!==null) ++$fields;
            if ($cardBody!==null) ++$fields;
            $cards[]=['title'=>$cardTitle,'body'=>$cardBody];
        }
        if ($fields!==0 && $fields!==6) {
            throw new RuntimeException('Incomplete homepage cards. Reload the editor and try again.');
        }
        $highlights=$fields===6?HighlightSchema::validate($cards):null;
        $assetId = null;
        if ($choice !== 'none') {
            if (preg_match('/^[1-9][0-9]{0,17}$/D', $choice) !== 1) throw new RuntimeException('Invalid image selection.');
            $assetId = (int)$choice;
        }
        $pageRepo = new PageRepository($pdo);
        $rawRevision=$_POST['page_revision']??null;
        $expectedRevision=is_string($rawRevision)
            ? filter_var($rawRevision,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])
            : false;
        if (!is_int($expectedRevision)) {
            throw new RuntimeException('Homepage changed; reload Design Studio before saving.');
        }
        $home = $pdo->query("SELECT id, status FROM ac_pages WHERE slug='home' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($home)) throw new RuntimeException('Create the homepage first in Pages.');
        PageRepository::validate('home',$title,$body,(string)$home['status']);
        $pdo->beginTransaction();
        try {
            $repo->save($theme,$accent,$side,$size,$assetId,$placement,$highlights);
            $pageRepo->save((int)$home['id'],'home',trim($title),$body,(string)$home['status'],$expectedRevision);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException
                && $e->getMessage()==='Page changed; reload before saving.') {
                throw new RuntimeException('Homepage changed; reload Design Studio before saving.');
            }
            throw $e;
        }
    }

    private function studio(PDO $pdo, DesignRepository $repo): void
    {
        $current = $repo->current();
        $home = $pdo->query("SELECT id,title,body,status,revision FROM ac_pages WHERE slug='home' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $title = is_array($home) ? (string)$home['title'] : 'Your members-only space';
        $body = is_array($home) ? (string)$home['body'] : 'An intimate, beautifully designed membership experience.';
        $selected = $current['hero_asset_id'] ?? null;
        $placement = CutoutPlacement::validate(
            (string)$current['cutout_x'], (string)$current['cutout_y'], (string)$current['cutout_width']
        );
        $notice = match ($_GET['result'] ?? '') {
            'saved' => '<p role="status">Your design and homepage text were saved. Publishing status is unchanged.</p>',
            'uploaded' => '<p role="status">Image uploaded privately. Choose it below and save the design to use it.</p>',
            default => '',
        };
        $csrf = self::e(AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
        $html = '<p class="intro">Choose a layout, change its colors, click the headline or paragraph to edit directly, and layer your own transparent cutout image over the theme background.</p>' . $notice;
        $html .= '<div class="design-studio"><section class="panel studio-controls"><h2>Theme & palette</h2><p class="muted">Four original themes—one selection each. Switching themes keeps their distinct layout and background styling; the model cutout is optional.</p>'
            . '<form id="design-form" method="post" action="/admin/design" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="page_revision" value="'.(int)($home['revision']??0).'">'
            . '<fieldset class="theme-picker"><legend>Choose a layout</legend>';
        foreach (ThemeCatalog::THEMES as $id=>$item) {
            $selectedTheme = $current['theme_key'] === $id;
            $html .= '<label class="theme-choice"><input type="radio" name="theme" value="' . self::e($id) . '" data-accent="' . self::e($item['accent']) . '"' . ($selectedTheme ? ' checked' : '') . '>'
                . '<span class="theme-choice-body"><span class="theme-choice-swatches">';
            foreach ($item['swatches'] as $swatch) {
                // Inline style is deliberately avoided under the site's strict CSP.
                $html .= '<i class="swatch swatch-' . self::e($id) . '-' . array_search($swatch,$item['swatches'],true) . '"></i>';
            }
            $html .= '</span><strong>' . self::e($item['name']) . '</strong><small>' . self::e($item['description']) . '</small></span></label>';
        }
        $html .= '</fieldset><label for="design-accent">Accent color</label>'
            . '<input type="color" id="design-accent" name="accent" value="' . self::e((string)$current['accent_color']) . '">'
            . '<label for="image-side">Cutout position</label><select id="image-side" name="image_side">';
        foreach (['right'=>'Right side','left'=>'Left side'] as $v=>$caption) {
            $html .= '<option value="' . $v . '"' . ($current['image_side']===$v?' selected':'') . '>' . $caption . '</option>';
        }
        $html .= '</select><label for="image-size">Initial size preset</label><select id="image-size" name="image_size">';
        foreach (['small'=>'Small','medium'=>'Medium','large'=>'Large'] as $v=>$caption) {
            $html .= '<option value="' . $v . '"' . ($current['image_size']===$v?' selected':'') . '>' . $caption . '</option>';
        }
        $html .= '</select><fieldset class="cutout-placement-controls"><legend>Drag & resize your cutout</legend>'
            . '<p class="muted">Drag the image or its Move handle over the background. Pull the corner handle to resize it live, or use these keyboard-friendly sliders.</p>';
        foreach ([
            ['cutout_x','Horizontal position',0,100,'X'],
            ['cutout_y','Vertical position',0,100,'Y'],
            ['cutout_width','Image width',20,100,'Size'],
        ] as [$field,$label,$min,$max,$short]) {
            $value = $placement[$field];
            $html .= '<label class="placement-slider-label" for="' . $field . '"><span>' . $label . '</span><output id="out-' . $field . '" for="' . $field . '">' . $value . '%</output></label>'
                . '<input class="placement-range" id="' . $field . '" name="' . $field . '" type="range" min="' . $min . '" max="' . $max . '" step="1" value="' . $value . '">';
        }
        $html .= '<button id="cutout-reset" type="button" class="button-secondary">Center and reset size</button></fieldset>'
            . '<fieldset class="asset-picker"><legend>Hero image</legend>'
            . '<label class="asset-choice"><input type="radio" name="hero_asset" value="none"' . ($selected === null?' checked':'') . '> No cutout — keep theme background</label>';
        foreach ($repo->assets() as $asset) {
            $id=(int)$asset['id'];
            $html .= '<label class="asset-choice"><input type="radio" name="hero_asset" value="' . $id . '"' . ($id===$selected?' checked':'') . '>'
                . '<img loading="lazy" src="/admin/design/asset/' . $id . '" alt="Uploaded image ' . $id . '">'
                . '<span>Image #' . $id . '<small>' . ((int)$asset['public_ok'] === 1 ? 'Public-safe asset' : 'Private preview only') . '</small></span></label>';
        }
        $html .= '</fieldset><label for="studio-title">Homepage headline</label><input id="studio-title" name="title" required maxlength="190" value="' . self::e($title) . '">'
            . '<label for="studio-body">Homepage text</label><textarea id="studio-body" name="body" maxlength="30000" rows="5">' . self::e($body) . '</textarea>'
            . '<fieldset class="home-info-card-editor"><legend>Homepage information cards</legend>'
            . '<p class="muted">Customize the three public information tiles under the homepage banner. Plain text only; no HTML, performer evidence or protected media.</p>';
        foreach (($current['highlights']??HighlightSchema::defaults()) as $index=>$card) {
            $number=$index+1;
            $html.='<label for="highlight-title-'.$number.'">Card '.$number.' heading</label>'
                .'<input id="highlight-title-'.$number.'" name="highlight_'.$number.'_title" maxlength="80" required value="'
                .self::e((string)$card['title']).'">'
                .'<label for="highlight-body-'.$number.'">Card '.$number.' description</label>'
                .'<textarea id="highlight-body-'.$number.'" name="highlight_'.$number.'_body" maxlength="240" rows="2" required>'
                .self::e((string)$card['body']).'</textarea>';
        }
        $html .= '</fieldset><button class="install-button" type="submit">Save design changes</button></form>'
            . '<hr><h2>Upload photos & cutouts</h2><p class="muted">Transparent PNG and WebP work best. JPG is also supported. Maximum 5 MB / 4096 px. Images with EXIF, GPS, text notes or unsupported metadata are rejected; export a metadata-free image first. No automatic background removal yet.</p>'
            . '<form method="post" action="/admin/design/upload" enctype="multipart/form-data" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<label for="design-upload">Picture</label><input id="design-upload" name="design_image" type="file" accept="image/png,image/webp,image/jpeg" required>'
            . '<label class="design-confirm"><input type="checkbox" name="public_use_confirm" value="1"> I confirm this is non-explicit, rights-cleared imagery suitable for the public website. Otherwise keep it private for preview.</label>'
            . '<button class="button-secondary" type="submit">Upload picture</button></form>'
            . '</section><section class="studio-preview-col"><div class="studio-preview-header"><div><p class="eyebrow">LIVE VISUAL EDITOR</p><h2>Click the words to edit</h2></div><a href="/" target="_blank" rel="noopener noreferrer" class="button-secondary">Open published site ↗</a></div>';
        $assetHtml = $selected !== null
            ? '<div class="cutout-positioner" id="studio-cutout">'
                . '<img class="design-portrait" alt="Selected decorative image preview" src="/admin/design/asset/' . (int)$selected . '">'
                . '<button class="cutout-move-handle" type="button" aria-label="Drag cutout to move">Move ✥</button>'
                . '<button class="cutout-resize-handle" type="button" aria-label="Drag cutout corner to resize">↘</button></div>'
            : '<span class="studio-preview-placeholder" role="status">+ Add your cutout photo<small>Theme background stays in place</small></span>';
        $html .= '<div class="studio-canvas theme-' . self::e((string)$current['theme_key']) . ' image-' . self::e((string)$current['image_side']) . ' image-size-' . self::e((string)$current['image_size']) . '" id="studio-canvas">'
            . '<div class="studio-canvas-copy"><p class="hero-kicker">PRIVATE · PERSONAL · YOURS</p>'
            . '<h2 id="visual-title" contenteditable="plaintext-only" role="textbox" aria-label="Edit homepage headline" aria-multiline="false" spellcheck="true">' . self::e($title) . '</h2>'
            . '<p id="visual-body" contenteditable="plaintext-only" role="textbox" aria-label="Edit homepage paragraph" aria-multiline="true" spellcheck="true">' . nl2br(self::e($body),false) . '</p>'
            . '<span class="btn btn-primary">Explore membership ↗</span></div>'
            . '<div class="studio-canvas-visual" id="studio-canvas-visual">' . $assetHtml . '</div></div>'
            . '<section class="site-highlights studio-info-preview" id="studio-info-cards" aria-label="Preview homepage information cards">';
        foreach (($current['highlights']??HighlightSchema::defaults()) as $index=>$card) {
            $number=$index+1;
            $html.='<div><span class="highlight-number">0'.$number.'</span>'
                .'<strong id="preview-highlight-title-'.$number.'">'.self::e((string)$card['title']).'</strong>'
                .'<p id="preview-highlight-body-'.$number.'">'.self::e((string)$card['body']).'</p></div>';
        }
        $html .= '</section>'
            . '<p class="studio-drag-hint" role="status" aria-live="polite" id="studio-drag-status">Select a cutout, then drag to move or pull its corner to resize. Changes are live until you save.</p>'
            . '<button class="button-secondary studio-change-image" id="studio-change-image" type="button">Change preview picture ↗</button>'
            . '<p class="muted">This preview is visible to administrators only. A private image will not appear on your published website until it has been marked suitable for public decorative use and selected in a saved design. Homepage draft/published status remains controlled in Pages.</p>'
            . '</section></div><script defer src="/assets/design-editor.js"></script>';
        $this->render(200,'Visual Design Studio',$html);
    }

    private function render(int $code, string $title, string $content): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">THEME BUILDER</p><h1>' . self::e($title) . '</h1></div><span class="page-heading-tag">Adult Core / Design</span></div>' . $content,
            '/admin/design',AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
