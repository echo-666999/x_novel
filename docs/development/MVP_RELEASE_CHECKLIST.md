# XNovel MVP 发布与恢复清单

本清单用于单人发布和定期恢复演练。每次正式发布前保存备份路径、执行时间和验证结果。不要在命令、日志或文档中写入密码和 API Key。

## 1. 发布前

- 在项目根目录执行 `php artisan test` 和 `npm run build`。
- 执行 `php artisan migrate:status`，确认没有未执行 Migration。
- 执行 `php artisan schedule:list`，确认包含 `generation:mark-stalled` 和 `horizon:snapshot`。
- 检查 Settings 页面的“系统健康”，处理所有“需要处理”项。
- 确认至少配置 `AI_DAILY_HARD_LIMIT`、`AI_NOVEL_TOTAL_LIMIT` 或 `AI_CHAPTER_MAX_COST` 中的一项。

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

- `php artisan horizon:status`：Horizon 正在运行。
- `php artisan queue:failed`：没有未处理的关键失败 Job。
- `php artisan schedule:list`：生产 Cron 正在执行 `schedule:run`。
- `storage/logs/laravel.log`：路径可写，无持续增长的同类异常。
- Emergency Stop：Settings 页开关可阻止新 Provider Request 和 Canonical Commit。
- `/up`：应用健康路由返回成功。

## 7. 发布记录

每次记录：版本/提交、备份文件、恢复演练时间、Migration 状态、Horizon 状态、Scheduler 状态、State/Memory 重建结果、未解决警告和回滚决定。
