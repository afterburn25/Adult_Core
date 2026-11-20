<?php

declare(strict_types=1);

namespace AdultCore\Design;

use PDO;
use RuntimeException;

/** Homepage visual settings are independent of CMS page text and member media. */
final class DesignRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function current(): array
    {
        $row = $this->pdo->query('SELECT theme_key, accent_color, hero_asset_id, image_side, image_size FROM ac_design_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return ThemeCatalog::defaults();
        $theme = (string) ($row['theme_key'] ?? '');
        $accent = (string) ($row['accent_color'] ?? '');
        $side = (string) ($row['image_side'] ?? '');
        $size = (string) ($row['image_size'] ?? '');
        try { ThemeCatalog::validate($theme, $accent, $side, $size); }
        catch (RuntimeException) { return ThemeCatalog::defaults(); }
        $row['hero_asset_id'] = isset($row['hero_asset_id']) ? (int)$row['hero_asset_id'] : null;
        $coordinates = CutoutPlacement::defaults();
        if (CutoutPlacement::installed($this->pdo)) {
            $columns = $this->pdo->query('SELECT cutout_x, cutout_y, cutout_width FROM ac_design_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($columns)) {
                try {
                    $coordinates = CutoutPlacement::validate(
                        (string)$columns['cutout_x'], (string)$columns['cutout_y'], (string)$columns['cutout_width']
                    );
                } catch (RuntimeException) {
                    // Broken persisted coordinates are never reflected into public CSS.
                }
            }
        }
        return array_merge($row, $coordinates);
    }

    /** @return list<array<string,mixed>> */
    public function assets(): array
    {
        return $this->pdo->query('SELECT id,mime,byte_size,public_ok,created_at FROM ac_design_assets ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function asset(int $id): ?array
    {
        if ($id <= 0) return null;
        $s = $this->pdo->prepare('SELECT id,filename,mime,byte_size,public_ok FROM ac_design_assets WHERE id=? LIMIT 1');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Do not expose unselected or private assets even if an ID becomes known. */
    public function publicAsset(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT a.id,a.filename,a.mime,a.byte_size FROM ac_design_assets a
           JOIN ac_design_settings d ON d.hero_asset_id=a.id AND d.id=1
           JOIN ac_pages p ON p.slug='home' AND p.status='published'
           WHERE a.id=? AND a.public_ok=1 LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function save(string $theme, string $accent, string $side, string $size, ?int $assetId, ?array $placement = null): void
    {
        ThemeCatalog::validate($theme, $accent, $side, $size);
        if ($assetId !== null && $this->asset($assetId) === null) {
            throw new RuntimeException('Choose an uploaded design image.');
        }
        if (!CutoutPlacement::installed($this->pdo)) {
            if ($placement !== null) {
                throw new RuntimeException('Apply the additive cutout-placement migration in Site setup before saving.');
            }
            // Existing integration clients may still save legacy settings before M12.
            $s = $this->pdo->prepare('UPDATE ac_design_settings SET theme_key=?,accent_color=?,image_side=?,image_size=?,hero_asset_id=?,updated_at=UTC_TIMESTAMP() WHERE id=1');
            $s->execute([$theme, strtolower($accent), $side, $size, $assetId]);
            return;
        }
        if ($placement === null) {
            $current = $this->current();
            $placement = [
                'cutout_x' => $current['cutout_x'],
                'cutout_y' => $current['cutout_y'],
                'cutout_width' => $current['cutout_width'],
            ];
        }
        $position = CutoutPlacement::validate(
            (string)($placement['cutout_x'] ?? ''),
            (string)($placement['cutout_y'] ?? ''),
            (string)($placement['cutout_width'] ?? '')
        );
        $s = $this->pdo->prepare('UPDATE ac_design_settings SET theme_key=?,accent_color=?,image_side=?,image_size=?,hero_asset_id=?,cutout_x=?,cutout_y=?,cutout_width=?,updated_at=UTC_TIMESTAMP() WHERE id=1');
        $s->execute([$theme, strtolower($accent), $side, $size, $assetId,
            $position['cutout_x'], $position['cutout_y'], $position['cutout_width']]);
    }
}
