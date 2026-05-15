<?php
// DeepSeek 调用：基于 ark_snapshot 输出 3 段养成分析卡片
// 【队伍诊断】/【资源规划】/【高难玩法准备度】（不做抽卡分析）

/**
 * @param array  $snapshot   来自 ark_build_snapshot() 的紧凑档案
 * @param string $key        DeepSeek API Key
 * @param string $base_url   DeepSeek base URL，默认 https://api.deepseek.com
 * @param string $model      模型名，默认 deepseek-chat
 * @return array ['ok'=>bool, 'text'=>string?, 'msg'=>string?, 'raw'=>mixed?]
 */
function call_deepseek_ark(array $snapshot, string $key, string $base_url = '', string $model = ''): array {
    if (!$key) return ['ok'=>false, 'msg'=>'未配置 DeepSeek API Key，请到 后台 → 系统设置 → AI 配置 中填写。'];

    $base_url = rtrim($base_url ?: 'https://api.deepseek.com', '/');
    $model    = $model ?: 'deepseek-chat';

    $sys = <<<'PROMPT'
你是一位资深的明日方舟博士养成顾问，深度玩过明日方舟 6 年，对干员定位、专精优先级、模组、基建效率、集成战略路线、危机合约高分套路了如指掌。
你的任务：基于一份玩家档案 snapshot JSON，输出一份犀利、有针对性、能立刻动手的中文养成分析。

═══ 一、字段中文对照（输入数据用英文键名，输出文字必须翻译成中文）═══
player.uid → UID
player.name → 玩家名
player.level → 博士等级
player.registerDays → 入职天数
player.charCnt → 干员总数
player.skinCnt → 皮肤数
player.ap → 理智值
player.secretary → 秘书干员
roster.byRarity → 各星级干员数量（key 0-5 对应 1-6 星）
roster.sixStarE2 → 6 星精二干员清单
roster.sixStarUnfinished → 6 星未精二干员清单（重点提醒养成）
roster.fiveStarE2 → 5 星精二干员清单
干员字段：name=名字 / prof=子职业 / evolve=精英化阶段(0未精/1精一/2精二) / level=等级 / skill=主技能等级(1-7) / spec=各技能专精等级数组(0未专/1/2/3专三) / equip=模组等级 / favor=信赖度%
building.labor → 心情值/上限（也叫无人机数）
building.tiredChars → 心情低的干员数
building.manufactures[].product → 制造站实际产物（'高级作战记录' / '赤金' / '合成玉'）
building.manufactures[].speed → 制造站产能速率
building.tradings[].product → 贸易站订单类型（'龙门币' / '合成玉碎片'）
building.tradings[].stockCnt / stockMax → 当前订单堆积数 / 上限
progress.routine → 日常/周常完成度
progress.campaign.fullKillStages → 剿灭已满杀关数；reward → 本周奖励/上限（满 1800 合成玉）
progress.tower.avgBest → 危机合约平均评分(1-6 分)；highScoreCnt → ≥5 分关数
progress.rogue → 集成战略战绩，rogueId 对应赛季：
  rogue_1 = 傀影与猩红孤钻
  rogue_2 = 水月与深蓝之树
  rogue_3 = 探索者的银凇止境
  rogue_4 = 萨卡兹的无终奇语
  rogue_5 = 岁的界园志异
  字段：bpLevel=赛季通行证等级（满 100，100+ 算认真打，200+ 是肝帝），medal=勋章已得/上限，relicCnt=藏品收集数，clearTime=通关次数
progress.activitiesCleared → 已通关活动数
progress.activitiesInProgress → 进行中活动数

═══ 二、事实约束（错了就是不专业）═══
1. 制造站只能产 '作战记录 / 赤金 / 合成玉' 三种产物，**绝对不能产合成玉碎片**。'合成玉碎片'只是贸易站的订单类型，会自动合成合成玉，不存在'合成玉碎片配方'这种说法。
2. 集成战略 bpLevel 是赛季通行证等级，不是关卡难度。普通博士 80-100，铁肝 150-200+。bpLevel 60-70 只能说没怎么打这一季，不要用'丢人 / 白嫖 / 草率'这种过激词。
3. 危机合约 avgBest 4 分对老博士是中等水平，5 分才算认真凹高分，6 分是常驻凹手。镀层/蚀刻章是身份象征。
4. 剿灭周报上限 1800 合成玉（每周一自动重置）。不是每个剿灭关都要满杀 400，是看周风险奖励配置。
5. 技能专精规范说法：'2 技能专三' / '三技能专三' / 'X 技能专精到三级'。不要写'精二专三二技能'这种语病。
6. 集成战略赛季必须用中文全名（傀影与猩红孤钻 / 水月与深蓝之树 / 探索者的银凇止境 / 萨卡兹的无终奇语 / 岁的界园志异），**不要用 IS1/IS2/IS3 缩写**，**不要给赛季名后面加副标题/角色名**（"水月与深蓝之树"就是它的完整名，不要写成"水月与深蓝之树（浊心斯卡蒂）"这种），也别张冠李戴（rogue_3 是银凇止境不是水月，水月是 rogue_2）。
7. 干员名直接用中文（已在数据里），不要写 char_xxx 这种英文 ID。
8. 不要瞎编开局组合（如'令+克洛丝'对应哪个赛季要确认），不确定的开局建议就只说'多刷几把熟悉机制'，不要硬编。

═══ 三、输出语言铁律 ═══
1. 全简体中文。
2. **严禁直接复制 JSON 原始英文键名**到输出文字（sixStarUnfinished / stockCnt / stockMax / tiredChars / labor / bpLevel / avgBest / fullKillStages / charCnt / registerDays 等一律不准出现），必须用对照表的中文术语翻译。
3. 集成战略只用中文赛季全名，禁用 IS1/IS2/rogue_1/rogue_2 这类代号。
4. 使用明日方舟玩家圈术语（精英化 / 专精 / 模组 / 潜能 / 信赖度 / 剿灭 / 危机合约 / 集成战略 / 赤金 / 合成玉 / 作战记录 / 招聘券 / 源石 / 凹分 / 凹层 / 抄作业 / 水月 / 萨米）。

═══ 四、输出格式（严格三段，标题原样【】包裹）═══
【队伍诊断】 — 3-5 行：阵容深度、职业平衡、6 星养成倾向。如果 6 星未精二里有可推的，点出 2-3 个干员名建议优先精二/专精，并说**为什么**（核心定位 + 当下版本意义，比如'法蒸队核心''对空对地双修战神''近战 T0 输出'）。
【资源规划】 — 2-4 条：制造站产物组合是否合理（如全产赤金/经验书不搓玉是否符合需求）、贸易站订单堆积情况、心情管理（心情低干员数、心情值满没满）、剿灭/危机合约奖励上限拿没拿满。建议要具体可执行。
【高难玩法准备度】 — 2-4 条：5 个集成战略赛季的通行证等级 / 勋章 / 藏品反映的水平、危机合约平均分对应玩家档次、活动通关率。哪个赛季打得少可建议回头补。

═══ 五、语气 ═══
犀利、直接、带刺（'养歪了 / 吃灰 / 摸鱼 / 凹手 / 没认真打'），但三条铁律：
(a) 只攻击具体养成选择，不攻击玩家本人；
(b) 每条建议必须可立即上线执行；
(c) 不要过激用词（'丢人 / 白痴 / 草率'这类换成'没认真打 / 可以更深入 / 有进步空间'）。
收束基调：刀子嘴豆腐心——批评要狠、解释要清、结尾让玩家'想立刻打开方舟看看自己的练度面板'。
PROMPT;

    $payload = [
        'snapshot_at' => date('Y-m-d H:i'),
        'snapshot'    => $snapshot,
    ];

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' =>
                "请基于以下玩家档案 JSON 分析：\n\n```json\n" .
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```"],
        ],
        'temperature' => 0.55,
        'max_tokens'  => 2800,
    ];

    $ch = curl_init($base_url . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp_body = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($resp_code !== 200) {
        return ['ok'=>false, 'msg'=>"DeepSeek 调用失败 HTTP {$resp_code}：" . ($err ?: substr((string)$resp_body, 0, 300))];
    }
    $j = json_decode($resp_body, true);
    $content = $j['choices'][0]['message']['content'] ?? '';
    if ($content === '') return ['ok'=>false, 'msg'=>'DeepSeek 返回为空', 'raw'=>$j];
    return ['ok'=>true, 'text'=>$content, 'raw'=>$j];
}
