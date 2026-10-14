<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const COMMENTS = [
        'ai_model_prices' => [
            'id' => '模型价格记录主键',
            'provider' => 'AI 服务商标识',
            'model' => '模型标识',
            'currency' => '计价货币代码',
            'billing_unit' => '价格对应的 Token 计费单位',
            'input_price' => '每计费单位输入 Token 价格',
            'cached_input_price' => '每计费单位缓存输入 Token 价格',
            'output_price' => '每计费单位输出 Token 价格',
            'is_enabled' => '是否启用该模型价格配置',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'context_window_tokens' => '模型上下文窗口 Token 上限',
            'max_output_tokens' => '模型单次最大输出 Token 数',
            'supports_structured_output' => '是否支持结构化输出',
            'supports_reasoning_effort' => '是否支持推理强度参数',
        ],
        'ai_model_routes' => [
            'id' => '模型路由记录主键',
            'role' => '生成阶段或模型职责标识',
            'provider' => '路由使用的 AI 服务商标识',
            'model' => '路由使用的模型标识',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'reasoning_effort' => '发送给模型的推理强度',
        ],
        'ai_provider_connections' => [
            'id' => 'AI 服务商连接记录主键',
            'provider' => 'AI 服务商唯一标识',
            'name' => '连接配置显示名称',
            'base_url' => 'AI 服务商接口基础地址',
            'api_key' => '加密保存的 API 密钥',
            'connect_timeout' => '建立连接超时秒数',
            'timeout' => '请求总超时秒数',
            'is_enabled' => '是否启用该连接',
            'last_verified_at' => '最近一次连接验证时间',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'cache' => [
            'key' => '缓存键',
            'value' => '序列化后的缓存值',
            'expiration' => '缓存过期时间戳',
        ],
        'cache_locks' => [
            'key' => '缓存锁键',
            'owner' => '缓存锁持有者标识',
            'expiration' => '缓存锁过期时间戳',
        ],
        'chapter_plans' => [
            'id' => '章节计划主键',
            'chapter_id' => '所属章节主键',
            'version' => '章节计划版本号',
            'chapter_function' => '本章在全书中的叙事功能',
            'arc_contribution' => '本章对主故事线的贡献说明',
            'reader_promise' => '本章向读者建立或兑现的阅读期待',
            'target_words' => '章节目标字数',
            'pov_character_id' => '视角人物主键',
            'tone' => '章节基调',
            'time_anchor' => '章节时间锚点',
            'hook_type' => '章节钩子类型',
            'must_reveal' => '本章必须揭示的内容列表',
            'may_hint' => '本章可以暗示的内容列表',
            'must_not_reveal' => '本章禁止提前揭示的内容列表',
            'required_facts' => '本章必须遵守的事实列表',
            'forbidden_conflicts' => '本章禁止出现的冲突列表',
            'due_foreshadowings' => '本章到期伏笔的历史引用列表',
            'scene_plans' => '按顺序冻结的场景计划列表',
            'status' => '章节计划状态',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'foreshadowing_actions' => '本章伏笔动作契约列表',
            'arc_contributions' => '本章对各故事线的结构化贡献列表',
            'world_entity_candidates' => '正文可引入的世界实体候选列表',
            'novel_outline_id' => '计划冻结的小说大纲版本主键',
            'primary_outline_arc_id' => '计划冻结的主大纲故事线主键',
            'primary_outline_beat_id' => '计划冻结的主大纲节拍主键',
            'primary_outline_milestone_id' => '计划冻结的主大纲里程碑主键',
            'character_candidates' => '正文可引入的人物候选列表',
            'checksum' => '章节计划语义内容校验和',
            'input_hash' => '章节计划准入输入哈希',
            'admission_snapshot' => '章节计划准入时冻结的执行合同快照',
            'admitted_at' => '章节计划通过准入的时间',
        ],
        'chapters' => [
            'id' => '章节主键',
            'novel_id' => '所属小说主键',
            'volume_id' => '所属分卷主键',
            'sequence' => '章节在小说中的顺序号',
            'title' => '章节标题',
            'status' => '章节生命周期状态',
            'canonical_artifact_id' => '已提交正式正文的生成产物主键',
            'word_count' => '章节正文统计字数',
            'summary' => '正式章节摘要',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'canonical_metadata' => '正式提交时冻结的附加元数据',
        ],
        'characters' => [
            'id' => '人物主键',
            'novel_id' => '所属小说主键',
            'name' => '人物名称',
            'aliases' => '人物别名列表',
            'role' => '人物叙事角色',
            'profile' => '人物基础档案',
            'motivation' => '人物核心动机',
            'personality' => '人物性格描述',
            'abilities' => '人物能力数据',
            'knowledge' => '人物已知信息数据',
            'current_state' => '人物当前正式状态',
            'locked_fields' => '禁止自动改写的锁定字段列表',
            'status' => '人物状态',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'source_chapter_id' => '首次确认该人物的来源章节主键',
            'source_candidate_key' => '来源候选人物的稳定键',
        ],
        'facts' => [
            'id' => '事实主键',
            'novel_id' => '所属小说主键',
            'subject_type' => '事实主体类型',
            'subject_id' => '事实主体标识',
            'predicate' => '事实谓词',
            'value' => '事实值',
            'hardness' => '事实约束强度',
            'confidence' => '事实置信度',
            'status' => '事实状态',
            'locked' => '是否为人工锁定事实',
            'source_type' => '事实来源类型',
            'source_event_id' => '来源故事事件主键',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'failed_jobs' => [
            'id' => '失败队列任务主键',
            'uuid' => '失败队列任务唯一标识',
            'connection' => '队列连接名称',
            'queue' => '队列名称',
            'payload' => '队列任务载荷',
            'exception' => '任务失败异常内容',
            'failed_at' => '任务失败时间',
        ],
        'foreshadowings' => [
            'id' => '伏笔主键',
            'novel_id' => '所属小说主键',
            'title' => '伏笔标题',
            'description' => '伏笔内容说明',
            'setup_chapter_id' => '首次埋设伏笔的章节主键',
            'promised_payoff' => '计划兑现内容',
            'due_from_chapter' => '建议开始兑现的章节序号',
            'due_to_chapter' => '最迟建议兑现的章节序号',
            'importance' => '伏笔重要度',
            'status' => '伏笔状态',
            'owner_arc_id' => '所属运行态故事线主键',
            'reinforce_count' => '伏笔已强化次数',
            'payoff_chapter_id' => '伏笔兑现章节主键',
            'notes' => '伏笔管理备注',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'management_history' => '伏笔管理操作历史',
        ],
        'generation_artifacts' => [
            'id' => '生成产物主键',
            'generation_run_id' => '所属生成运行主键',
            'type' => '生成产物类型',
            'version' => '同类产物版本号',
            'content' => '生成产物文本内容',
            'data' => '生成产物结构化数据',
            'checksum' => '生成产物内容校验和',
            'created_at' => '生成产物创建时间',
        ],
        'generation_runs' => [
            'id' => '生成运行主键',
            'novel_id' => '所属小说主键',
            'chapter_id' => '关联章节主键',
            'scene_id' => '关联场景主键',
            'scope_type' => '生成运行作用域类型',
            'scope_id' => '生成运行作用域对象主键',
            'stage' => '生成流程阶段',
            'status' => '生成运行状态',
            'attempt' => '当前执行尝试次数',
            'idempotency_key' => '生成运行幂等键',
            'input_hash' => '生成输入内容哈希',
            'state_version' => '生成时使用的故事状态版本号',
            'bible_version' => '生成时使用的小说圣经版本号',
            'prompt_version' => '生成时使用的提示词版本',
            'model_policy' => '生成时冻结的模型策略标识',
            'context_snapshot' => '生成时使用的上下文快照',
            'error_code' => '生成失败错误代码',
            'error_message' => '生成失败错误信息',
            'started_at' => '生成运行开始时间',
            'finished_at' => '生成运行结束时间',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'provider' => '实际使用的 AI 服务商标识',
            'error_retryable' => '失败是否允许技术重试',
            'error_metadata' => '失败诊断结构化元数据',
            'progression_failure' => '阶段成功后的流程推进失败记录',
        ],
        'job_batches' => [
            'id' => '队列批次唯一标识',
            'name' => '队列批次名称',
            'total_jobs' => '批次任务总数',
            'pending_jobs' => '批次待处理任务数',
            'failed_jobs' => '批次失败任务数',
            'failed_job_ids' => '批次失败任务标识列表',
            'options' => '队列批次配置数据',
            'cancelled_at' => '队列批次取消时间戳',
            'created_at' => '队列批次创建时间戳',
            'finished_at' => '队列批次完成时间戳',
        ],
        'jobs' => [
            'id' => '队列任务主键',
            'queue' => '队列名称',
            'payload' => '队列任务载荷',
            'attempts' => '任务已尝试次数',
            'reserved_at' => '任务被保留的时间戳',
            'available_at' => '任务可执行时间戳',
            'created_at' => '任务创建时间戳',
        ],
        'memories' => [
            'id' => '长期记忆主键',
            'novel_id' => '所属小说主键',
            'type' => '记忆类型',
            'source_type' => '记忆来源类型',
            'source_id' => '记忆来源对象主键',
            'summary' => '可检索的记忆摘要',
            'entities' => '记忆关联实体数据',
            'salience' => '记忆显著性评分',
            'status' => '记忆状态',
            'embedding_model' => '生成向量所用模型标识',
            'valid_from_chapter' => '记忆开始有效的章节序号',
            'valid_to_chapter' => '记忆结束有效的章节序号',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'embedding' => '用于语义检索的向量数据',
        ],
        'migrations' => [
            'id' => '迁移执行记录主键',
            'migration' => '已执行的迁移名称',
            'batch' => '迁移所属批次号',
        ],
        'novel_bibles' => [
            'id' => '小说圣经主键',
            'novel_id' => '所属小说主键',
            'version' => '小说圣经版本号',
            'logline' => '小说一句话梗概',
            'themes' => '小说主题列表',
            'tone' => '小说整体基调',
            'pov' => '小说视角规则',
            'tense' => '小说叙事时态',
            'taboos' => '小说禁止事项列表',
            'hard_constraints' => '小说硬约束列表',
            'ending_contract' => '小说结局合同',
            'status' => '小说圣经状态',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'style_profile' => '小说文风配置',
        ],
        'novel_outline_arcs' => [
            'id' => '大纲故事线主键',
            'novel_outline_id' => '所属小说大纲版本主键',
            'novel_outline_volume_id' => '所属大纲分卷主键',
            'arc_key' => '故事线稳定业务键',
            'sequence' => '故事线在分卷内的顺序号',
            'mainline_sequence' => '主线故事线的全局顺序号',
            'type' => '故事线类型',
            'title' => '故事线标题',
            'goal' => '故事线目标',
            'stakes' => '故事线风险与代价',
            'completion_conditions' => '故事线完成条件列表',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'novel_outline_beats' => [
            'id' => '大纲节拍主键',
            'novel_outline_id' => '所属小说大纲版本主键',
            'novel_outline_arc_id' => '所属大纲故事线主键',
            'beat_key' => '节拍稳定业务键',
            'sequence' => '节拍在故事线内的顺序号',
            'mainline_sequence' => '主线节拍的全局顺序号',
            'title' => '节拍标题',
            'summary' => '节拍摘要',
            'chapter_budget_min' => '节拍最少章节预算',
            'chapter_budget_max' => '节拍最多章节预算',
            'acceptance_criteria' => '节拍验收条件列表',
            'must_include' => '节拍必须包含的内容列表',
            'must_not_include' => '节拍禁止包含的内容列表',
            'character_candidates' => '节拍允许引入的人物候选列表',
            'world_entity_candidates' => '节拍允许引入的世界实体候选列表',
            'handoff_next_beat_id' => '交接目标节拍主键',
            'handoff_transition_mode' => '节拍交接方式',
            'handoff_exit_result' => '当前节拍结束时必须形成的结果',
            'handoff_next_trigger' => '触发下一节拍的条件',
            'handoff_carried_states' => '交接到下一节拍的状态列表',
            'handoff_open_threads' => '交接时保留的未结事项列表',
            'handoff_required_transition' => '交接必须满足的过渡要求列表',
            'handoff_forbidden_jump' => '交接禁止发生的剧情跳跃列表',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'novel_outline_milestones' => [
            'id' => '大纲里程碑主键',
            'novel_outline_id' => '所属小说大纲版本主键',
            'novel_outline_beat_id' => '所属大纲节拍主键',
            'milestone_key' => '里程碑稳定业务键',
            'sequence' => '里程碑在节拍内的顺序号',
            'title' => '里程碑标题',
            'objective' => '里程碑目标',
            'acceptance_criteria' => '里程碑验收条件列表',
            'must_include' => '里程碑必须包含的内容列表',
            'must_not_include' => '里程碑禁止包含的内容列表',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'novel_outline_volumes' => [
            'id' => '大纲分卷主键',
            'novel_outline_id' => '所属小说大纲版本主键',
            'volume_key' => '分卷稳定业务键',
            'sequence' => '分卷在大纲中的顺序号',
            'title' => '分卷标题',
            'goal' => '分卷目标',
            'climax' => '分卷高潮设计',
            'target_words' => '分卷目标字数',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'novel_outlines' => [
            'id' => '小说大纲版本主键',
            'novel_id' => '所属小说主键',
            'version' => '小说大纲版本号',
            'status' => '小说大纲版本状态',
            'source' => '小说大纲来源类型',
            'schema_version' => '大纲数据结构版本号',
            'title' => '小说大纲标题',
            'summary' => '小说大纲摘要',
            'must_include' => '全书必须包含的内容列表',
            'must_not_include' => '全书禁止包含的内容列表',
            'checksum' => '小说大纲内容校验和',
            'source_artifact_id' => '生成该大纲的来源产物主键',
            'based_on_outline_id' => '修订所基于的大纲版本主键',
            'created_by' => '创建该大纲版本的用户主键',
            'applied_at' => '大纲版本正式应用时间',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'novels' => [
            'id' => '小说主键',
            'title' => '小说标题',
            'genre' => '小说题材类型',
            'premise' => '小说核心设定',
            'target_words' => '全书目标字数',
            'status' => '小说生命周期状态',
            'current_chapter_sequence' => '当前正式章节序号',
            'settings' => '小说级策略与配置',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'canonical_state_version_id' => '当前正式故事状态版本主键',
            'current_outline_id' => '当前生效的小说大纲版本主键',
        ],
        'password_reset_tokens' => [
            'email' => '申请重置密码的邮箱',
            'token' => '密码重置令牌',
            'created_at' => '密码重置令牌创建时间',
        ],
        'reviews' => [
            'id' => '章节审校结果主键',
            'generation_run_id' => '审校生成运行主键',
            'artifact_id' => '被审校的生成产物主键',
            'decision' => '审校决策',
            'score' => '审校综合分数',
            'continuity_score' => '事实与连续性分数',
            'plan_score' => '计划遵循分数',
            'character_score' => '人物一致性分数',
            'progress_score' => '剧情推进分数',
            'repetition_score' => '重复度分数',
            'pacing_score' => '节奏与悬念分数',
            'style_score' => '文风与可读性分数',
            'findings' => '审校问题明细',
            'created_at' => '审校结果创建时间',
        ],
        'scenes' => [
            'id' => '场景主键',
            'chapter_id' => '所属章节主键',
            'sequence' => '场景在章节内的顺序号',
            'pov_character_id' => '场景视角人物主键',
            'location' => '场景发生地点',
            'time_anchor' => '场景时间锚点',
            'goal' => '场景目标',
            'conflict' => '场景冲突',
            'turn' => '场景转折',
            'outcome' => '场景结果',
            'status' => '场景生成状态',
            'current_artifact_id' => '场景当前采用的生成产物主键',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'sessions' => [
            'id' => '会话唯一标识',
            'user_id' => '登录用户主键',
            'ip_address' => '客户端 IP 地址',
            'user_agent' => '客户端浏览器标识',
            'payload' => '序列化后的会话数据',
            'last_activity' => '会话最近活动时间戳',
        ],
        'story_arcs' => [
            'id' => '运行态故事线主键',
            'novel_id' => '所属小说主键',
            'volume_id' => '所属分卷主键',
            'type' => '故事线类型',
            'title' => '故事线标题',
            'goal' => '故事线目标',
            'stakes' => '故事线风险与代价',
            'completion_conditions' => '故事线完成条件列表',
            'progress' => '故事线完成进度',
            'status' => '故事线状态',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'source_outline_arc_id' => '来源大纲故事线主键',
            'sequence' => '故事线在小说中的顺序号',
        ],
        'story_events' => [
            'id' => '正式故事事件主键',
            'novel_id' => '所属小说主键',
            'chapter_id' => '来源正式章节主键',
            'scene_id' => '来源场景主键',
            'event_type' => '故事事件类型',
            'subject_type' => '事件主体类型',
            'subject_id' => '事件主体标识',
            'payload' => '事件结构化载荷',
            'evidence' => '支持事件的正文证据列表',
            'story_time' => '故事内时间描述',
            'state_version' => '事件所属的故事状态版本号',
            'status' => '故事事件状态',
            'invalidated_at' => '故事事件失效时间',
            'created_at' => '故事事件创建时间',
            'novel_outline_id' => '事件对应的小说大纲版本主键',
            'novel_outline_arc_id' => '事件对应的大纲故事线主键',
            'novel_outline_beat_id' => '事件对应的大纲节拍主键',
            'novel_outline_milestone_id' => '事件对应的大纲里程碑主键',
        ],
        'story_state_versions' => [
            'id' => '故事状态版本主键',
            'novel_id' => '所属小说主键',
            'version' => '故事状态版本号',
            'chapter_id' => '产生该状态版本的正式章节主键',
            'state' => '该版本的完整故事状态快照',
            'checksum' => '故事状态内容校验和',
            'created_at' => '故事状态版本创建时间',
        ],
        'system_settings' => [
            'key' => '系统设置键',
            'value' => '系统设置值',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'usage_records' => [
            'id' => 'AI 用量记录主键',
            'generation_run_id' => '关联生成运行主键',
            'provider' => '实际请求的 AI 服务商标识',
            'model' => '实际请求的模型标识',
            'input_tokens' => '输入 Token 数',
            'output_tokens' => '输出 Token 数',
            'cached_tokens' => '命中缓存的输入 Token 数',
            'latency_ms' => '请求耗时毫秒数',
            'estimated_cost' => '预估请求费用',
            'request_id' => '服务商返回的请求标识',
            'created_at' => '用量记录创建时间',
            'novel_id' => '关联小说主键',
            'chapter_id' => '关联章节主键',
            'request_metadata' => '请求参数与完成原因等非敏感元数据',
            'reasoning_tokens' => '模型推理 Token 数',
        ],
        'users' => [
            'id' => '用户主键',
            'name' => '用户名称',
            'email' => '用户登录邮箱',
            'email_verified_at' => '邮箱验证时间',
            'password' => '哈希后的登录密码',
            'remember_token' => '保持登录状态令牌',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
        ],
        'volumes' => [
            'id' => '运行态分卷主键',
            'novel_id' => '所属小说主键',
            'sequence' => '分卷在小说中的顺序号',
            'title' => '分卷标题',
            'goal' => '分卷目标',
            'climax' => '分卷高潮设计',
            'target_words' => '分卷目标字数',
            'status' => '分卷状态',
            'summary' => '分卷摘要',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'source_outline_volume_id' => '来源大纲分卷主键',
        ],
        'world_entities' => [
            'id' => '世界实体主键',
            'novel_id' => '所属小说主键',
            'type' => '世界实体类型',
            'name' => '世界实体名称',
            'description' => '世界实体说明',
            'attributes' => '世界实体属性数据',
            'rules' => '世界实体规则数据',
            'current_state' => '世界实体当前正式状态',
            'locked_fields' => '禁止自动改写的锁定字段列表',
            'status' => '世界实体状态',
            'created_at' => '记录创建时间',
            'updated_at' => '记录更新时间',
            'source_chapter_id' => '首次确认该实体的来源章节主键',
            'source_candidate_key' => '来源候选实体的稳定键',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertSchemaMatchesComments();
        $this->applyComments(false);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertSchemaMatchesComments();
        $this->applyComments(true);
    }

    private function applyComments(bool $remove): void
    {
        $connection = DB::connection();
        $schemaGrammar = $connection->getSchemaGrammar();
        $queryGrammar = $connection->getQueryGrammar();

        foreach (self::COMMENTS as $table => $columns) {
            $wrappedTable = $schemaGrammar->wrapTable($table);

            foreach ($columns as $column => $comment) {
                $wrappedColumn = $queryGrammar->wrap($column);
                $value = $remove ? 'NULL' : $connection->getPdo()->quote($comment);

                DB::statement("COMMENT ON COLUMN {$wrappedTable}.{$wrappedColumn} IS {$value}");
            }
        }
    }

    private function assertSchemaMatchesComments(): void
    {
        $connection = DB::connection();
        $prefix = $connection->getTablePrefix();
        $rows = DB::select(<<<'SQL'
            SELECT c.table_name, c.column_name
            FROM information_schema.columns c
            INNER JOIN information_schema.tables t
                ON t.table_schema = c.table_schema
                AND t.table_name = c.table_name
            WHERE c.table_schema = current_schema()
                AND t.table_type = 'BASE TABLE'
            ORDER BY c.table_name, c.ordinal_position
        SQL);

        $actual = [];
        foreach ($rows as $row) {
            $table = (string) $row->table_name;
            $logicalTable = $prefix !== '' && str_starts_with($table, $prefix)
                ? substr($table, strlen($prefix))
                : $table;
            $actual[$logicalTable][] = (string) $row->column_name;
        }

        $expectedTables = array_keys(self::COMMENTS);
        $actualTables = array_keys($actual);
        sort($expectedTables);
        sort($actualTables);

        if ($expectedTables !== $actualTables) {
            throw new RuntimeException(sprintf(
                '字段备注映射与数据库表不一致。缺少映射：%s；数据库缺少：%s。',
                implode(', ', array_diff($actualTables, $expectedTables)) ?: '无',
                implode(', ', array_diff($expectedTables, $actualTables)) ?: '无',
            ));
        }

        foreach (self::COMMENTS as $table => $comments) {
            $expectedColumns = array_keys($comments);
            $actualColumns = $actual[$table];

            if ($expectedColumns !== $actualColumns) {
                throw new RuntimeException(sprintf(
                    '字段备注映射与表 %s 的字段不一致。缺少映射：%s；数据库缺少：%s；字段顺序也必须一致。',
                    $table,
                    implode(', ', array_diff($actualColumns, $expectedColumns)) ?: '无',
                    implode(', ', array_diff($expectedColumns, $actualColumns)) ?: '无',
                ));
            }
        }
    }
};
