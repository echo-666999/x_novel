# XNovel MVP 发布与恢复清单

本清单用于单人发布和定期恢复演练。每次正式发布前保存备份路径、执行时间和验证结果。不要在命令、日志或文档中写入密码和 API Key。

## 1. 发布前

- 在项目根目录先执行与本次改动直接相关的目标测试，再执行 `php artisan test`；前端资源有变化时执行 `npm run build`。
- 记录每条测试命令的测试数、断言数、跳过数、失败数和执行时间。不得把上一次发布或另一环境的结果当成本次结果。
- 执行 `php artisan migrate:status`，逐项确认没有未执行 Migration，并把命令输出时间和结论写入发布记录。
- 执行 `php artisan schedule:list`，确认包含 `generation:mark-stalled` 和 `horizon:snapshot`。
- 检查 Settings 页面的“系统健康”，处理所有“需要处理”项。
- 确认至少配置 `AI_DAILY_HARD_LIMIT`、`AI_NOVEL_TOTAL_LIMIT` 或 `AI_CHAPTER_MAX_COST` 中的一项。
- 核实 `AI_PROVIDER`、`AI_BASE_URL` 和实际模型在当前端点/账户可用。Stage 模型留空表示继承全局 `AI_MODEL`；不要把 `.env.example` 当成历史 Run 或生产配置证据，也不要把 API Key 复制进发布记录。

章节流水线相关发布至少执行：

```bash
php artisan test tests/Feature/AiSettingsResolverTest.php tests/Feature/ChapterPipelineOrchestrationTest.php tests/Feature/StyleContractPipelineTest.php tests/Feature/RewriteLoopTest.php tests/Feature/AutoGenerationTest.php tests/Feature/CanonicalCommitServiceTest.php
php artisan test
```

## 2. PostgreSQL 备份

使用 `.pgpass` 或运行环境的 Secret Manager 提供认证：

```bash
pg_dump --format=custom --no-owner --file=xnovel-YYYYMMDD-HHMM.dump x_novel
pg_restore --list xnovel-YYYYMMDD-HHMM.dump
```

记录备份文件的大小、checksum 和异地副本位置。

## 3. 隔离恢复演练

恢复目标必须是临时数据库，不得覆盖生产库：

```bash
createdb x_novel_restore_test
pg_restore --clean --if-exists --no-owner --dbname=x_novel_restore_test xnovel-YYYYMMDD-HHMM.dump
```

对比 `novels`、`chapters`、`story_state_versions`、`story_events`、`facts`、`memories`、`generation_runs` 和 `usage_records` 的行数，并抽查最新 Canonical Chapter 与 Novel Pointer。演练完成后删除临时库。

## 4. 部署与 Queue 重启

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate
php artisan horizon:status
```

`horizon:terminate` 依赖 Supervisor 或其他进程管理器自动重启 Horizon。确认 Horizon 同时监听 `generation` 和 `default` Queue。

macOS + Herd 本地环境使用项目内的 launchd 配置常驻 Horizon：

```bash
mkdir -p "$HOME/Library/LaunchAgents"
cp ops/launchd/com.xnovel.horizon.plist "$HOME/Library/LaunchAgents/com.xnovel.horizon.plist"
launchctl bootstrap "gui/$(id -u)" "$HOME/Library/LaunchAgents/com.xnovel.horizon.plist"
launchctl enable "gui/$(id -u)/com.xnovel.horizon"
launchctl kickstart -k "gui/$(id -u)/com.xnovel.horizon"
```

该 LaunchAgent 使用 `RunAtLoad + KeepAlive`，登录后自动启动，异常退出或执行 `horizon:terminate` 后自动恢复。安装后不要再在终端手工启动第二个 Horizon Master。配置中的项目路径和 Herd PHP 路径是本机绝对路径，移动项目或切换用户后必须同步修改。

## 5. State 与 Memory 恢复

先校验 Story State：

```bash
php artisan story:rebuild-state NOVEL_ID --dry-run
```

只有 checksum 和状态差异符合预期时才继续。Memory 来自 Canonical Chapter，重建使用幂等的 Memory Update：

```bash
php artisan memory:rebuild NOVEL_ID
```

需要前台观察失败时可以在维护窗口使用 `--sync`。

## 6. 运行检查

- `php artisan horizon:status`：记录实际输出；正式运行环境必须为 `Horizon is running`，否则发布不通过。
- `php artisan queue:failed`：没有未处理的关键失败 Job。
- `php artisan schedule:list`：生产 Cron 正在执行 `schedule:run`。
- `storage/logs/laravel.log`：路径可写，无持续增长的同类异常。
- Emergency Stop：Settings 页开关可阻止新 Provider Request 和 Canonical Commit。
- `/up`：应用健康路由返回成功。

章节流程抽查：

- “生成下一章”能自动推进至 Review PASS，过程中不要求逐 Scene 点击。
- PASS 后 Chapter 仍不是 Canonical，Story State、Story Event 和正式 Memory 尚未更新；章节工作台显示“提交正式章节”。
- 用户确认“提交正式章节”后才执行 Canonical Commit。
- 缺少完整 Current Bible/Style Profile 时显示 `current_bible_incomplete`，在“小说圣经”创建新版本后可以重新启动。
- Rewrite 耗尽后停在 NEEDS_ATTENTION，并显示“人工修改正文”；符合条件时才显示或启用人工 Override。
- 暂停后的“恢复”从数据库中的 Run/Artifact 断点继续；PASS 恢复点仍等待人工提交。

## 7. 发布记录

每次记录：版本/提交、备份文件、恢复演练时间、Migration 状态、Horizon 原始状态、Scheduler 状态、目标测试与完整测试的实际结果、章节流程抽查结果、State/Memory 重建结果、未解决警告和回滚决定。
