<?php

namespace App\Console\Commands;

use App\Services\ForeshadowingHistoryRepairService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class RepairForeshadowingHistory extends Command
{
    protected $signature = 'foreshadowing:repair-history
        {plan : 冻结修复计划 JSON 文件}
        {--execute : 显式执行冻结计划；省略时只进行 dry-run}
        {--actor= : 执行者 User ID；--execute 首次执行时必填}';

    protected $description = '按正文证据和冻结计划修复历史伏笔事件、Canonical State、Memory 与领域投影';

    public function handle(ForeshadowingHistoryRepairService $repair): int
    {
        $path = $this->absolutePath((string) $this->argument('plan'));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("无法读取修复计划：{$path}");

            return self::FAILURE;
        }

        try {
            $plan = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($plan)) {
                throw new JsonException('计划根节点必须是对象。');
            }

            if ($this->option('execute') && ($applied = $repair->appliedResult($plan)) !== null) {
                $this->info("该冻结计划已经执行到 Canonical State v{$applied['result_state_version']}，未产生重复写入。");

                return self::SUCCESS;
            }

            $preview = $repair->preview($plan);
        } catch (JsonException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('无法完成 dry-run：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('小说', "{$preview['novel_title']} (#{$preview['novel_id']})");
        $this->components->twoColumnDetail('冻结计划', $preview['repair_key']);
        $this->components->twoColumnDetail('Canonical', "v{$preview['expected_state_version']} → v{$preview['result_state_version']}");
        $this->components->twoColumnDetail('完整基线', 'v'.$preview['baseline_state_version']);
        $this->components->twoColumnDetail('事件修复', (string) count($preview['event_repairs']));
        $this->components->twoColumnDetail('状态修正', (string) count($preview['state_corrections']));
        $this->components->twoColumnDetail('未决项', (string) count($preview['unresolved_items']));
        $this->components->twoColumnDetail('状态差异', (string) count($preview['state_changes']));
        $this->components->twoColumnDetail('受影响 Fact', (string) $preview['facts_affected']);
        $this->components->twoColumnDetail('当前人物投影漂移', json_encode($preview['projection_before']['character_drift_ids']));
        $this->components->twoColumnDetail('当前世界投影漂移', json_encode($preview['projection_before']['world_entity_drift_ids']));
        $this->components->twoColumnDetail('当前伏笔投影漂移', json_encode($preview['projection_before']['foreshadowing_drift_ids']));
        $this->components->twoColumnDetail('将重建伏笔投影', json_encode($preview['foreshadowing_projection_ids_to_rebuild']));

        foreach ($preview['event_repairs'] as $item) {
            $after = $item['replacement_event_type'] ?? '失效且不替换';
            $this->line("Event #{$item['event_id']}: {$item['expected_event_type']} → {$after}; Memory {$item['memory_records_affected']} 条；{$item['reason']}");
        }
        foreach ($preview['state_corrections'] as $item) {
            $this->line("State {$item['path']} → ".json_encode($item['value'], JSON_UNESCAPED_UNICODE)."；{$item['reason']}");
        }
        foreach ($preview['state_changes'] as $change) {
            $before = json_encode($change['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $after = json_encode($change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->line("Canonical {$change['path']}: {$before} → {$after}");
        }
        foreach ($preview['unresolved_items'] as $item) {
            $this->warn('未决：'.(is_array($item) ? ($item['description'] ?? json_encode($item, JSON_UNESCAPED_UNICODE)) : $item));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY-RUN：未修改 Story Event、Canonical State、Memory 或领域投影。');

            return self::SUCCESS;
        }

        try {
            $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $result = $repair->execute($plan, $actorId === false ? null : $actorId);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('历史修复失败，事务已回滚：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['status'] === 'already_applied'
            ? '该冻结计划已经执行，未产生重复写入。'
            : "修复完成：Canonical State v{$result['result_state_version']}，领域投影一致。");

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
