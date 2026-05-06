# 缪斯 MUSE 论坛 — 维护手册

> 最后更新：2026-04-20
> GitHub：https://github.com/lingyunalingyun/Muse-Forum

---

## 目录

1. [项目概览](#1-项目概览)
2. [目录结构](#2-目录结构)
3. [数据库结构](#3-数据库结构)
4. [模块说明](#4-模块说明)
5. [角色与权限](#5-角色与权限)
6. [经验与等级](#6-经验与等级)
7. [封禁系统](#7-封禁系统)
8. [举报系统](#8-举报系统)
9. [转发与动态](#9-转发与动态)
10. [交友页](#10-交友页)
11. [客服系统](#11-客服系统)
12. [在线曲库](#12-在线曲库云端曲谱)
13. [配置说明](#13-配置说明)
13. [开发工作流](#13-开发工作流)
14. [常见维护操作](#14-常见维护操作)
15. [部署注意事项](#15-部署注意事项)

---

## 1. 项目概览

| 项目 | 说明 |
|------|------|
| 站点名称 | 缪斯 MUSE |
| 主题风格 | Dark Terminal（暗色终端，游戏/科研方向） |
| 主色调 | `#3fb950`（绿）/ 背景 `#0d1117` + `#161b22` |
| 语言 | PHP 7.x + MySQL + 原生 JS |
| 编辑器 | WangEditor（富文本） |

**两个文件夹说明：**

| 文件夹 | 用途 |
|--------|------|
| `论坛开发_本地版` | 开发版，本地调试用，含真实 DB 密码，代码无注释 |
| `论坛开发_上传版` | 上传版，敏感信息脱敏，含完整注释，推送 GitHub |

**工作流：** 本地版修改 → 复制到上传版 → 检查敏感信息 → 推送 GitHub

---

## 2. 目录结构

```
/
├── config.php                  全局配置（DB 连接、站点常量）
├── index.php                   首页（推荐帖 2列大图网格 + 侧栏）
├── square.php                  广场（全部帖子，分页 + 分区筛选，黑名单过滤）
├── categories.php              分区入口（PS5 游戏库风格 16:10 大图卡片）
├── dating.php                  交友页（滚动卡片墙，投放 / 查看交友卡片）
├── search.php                  全站搜索（帖子 + 用户 Tab）
├── topic.php                   话题页（#标签聚合帖子列表）
├── sheets.php                  在线曲库主页（公开浏览/搜索/下载，给 SkyMusic 桌面端用）
├── style.css                   全站基础样式
│
├── includes/
│   ├── header.php              全局导航栏（粘性顶栏 + 封禁检测 + 封禁横幅）
│   ├── exp_helper.php          核心辅助函数库（经验 / 等级 / 角色 / 邮件 / DB 迁移 / 可见性）
│   ├── repost_card.php         转发卡片渲染函数 render_repost_card()
│   └── text_format.php         文本格式化（#话题 @提及 渲染）
│
├── pages/
│   ├── login.php               登录页
│   ├── register.php            注册页（含邮箱验证状态提示）
│   ├── logout.php              登出（销毁 session）
│   ├── verify.php              邮箱验证（token 24h）
│   ├── forgot_password.php     忘记密码页
│   ├── reset_password.php      重置密码页（token 1h）
│   ├── profile.php             用户主页（头像 / 等级 / 帖子 / 收藏 / 点赞 Tab / 关注粉丝弹窗）
│   ├── edit_profile.php        编辑资料（头像 / 昵称 / 签名 / 邮箱）
│   ├── settings.php            个人设置（隐私 + 黑名单）
│   ├── publish.php             发帖页（WangEditor）
│   ├── post.php                帖子详情（内容 + 评论树 + 可见性检查 + 举报）
│   ├── moments.php             动态页（自己 + 关注者帖子，含转发）
│   ├── notices.php             社区公告列表
│   ├── notifications.php       消息中心（含帖子引用卡片）
│   ├── report_modal.php        举报弹窗组件（被 post.php / profile.php include）
│   ├── admin.php               管理后台（待审核帖子审核）
│   ├── admin_ban.php           封禁管理后台（限时 / 永久，快速解封）
│   ├── admin_categories.php    分区管理（创建 / 编辑 / 删除）
│   ├── admin_messages.php      私信查询后台
│   ├── admin_reports.php       举报管理后台（删帖 / 封号 / 驳回 / 处理）
│   ├── cs_chat.php             客服聊天页（用户端）
│   └── cs_panel.php            客服工作台（客服 / 管理端）
│
├── actions/                    AJAX / 表单处理端点（均接收 POST）
│   ├── auth.php                认证（注册 / 登录 / 找回密码 / 重置密码 / 重发验证）
│   ├── save.php                发帖 / 保存草稿（支持转发 repost_id）
│   ├── post_action.php         帖子点赞 / 收藏 toggle（返回 JSON）
│   ├── post_delete.php         删除帖子（级联删评论 / 点赞 / 收藏）
│   ├── post_edit.php           编辑帖子（10 分钟冷却）
│   ├── post_recommend.php      推荐 / 取消推荐（admin/owner）
│   ├── comment_save.php        提交评论 / 回复
│   ├── comment_delete.php      删除评论（级联子回复）
│   ├── comment_like.php        评论点赞 toggle（返回 JSON）
│   ├── comment_top.php         评论置顶 toggle（admin/owner）
│   ├── follow_toggle.php       关注 / 取消关注（返回 JSON）
│   ├── block_user.php          拉黑 / 取消拉黑（拉黑时自动解除双向关注）
│   ├── ban_user.php            封禁 / 解封（支持限时 + 永久，admin/owner）
│   ├── message_send.php        发送私信（支持帖子引用卡片 ref_post_id）
│   ├── report_submit.php       提交举报（type: post|user）
│   ├── social_card_save.php    交友卡片投放 / 删除 / 管理员删除
│   ├── upload_image.php        WangEditor 图片上传（返回 errno/url JSON）
│   ├── upload_attachment.php   附件上传
│   ├── user_search.php         @提及用户名搜索（返回 JSON）
│   └── cs_action.php           客服 action 端点
│
├── uploads/
│   ├── avatars/                用户头像
│   ├── posts/                  帖子内图片
│   ├── attachments/            帖子附件
│   ├── categories/             分区封面图
│   └── sheets/                 在线曲库曲谱文件 (.txt / .json，≤5MB)
│
├── api/
│   └── sheets/                 给 SkyMusic 桌面端的公开 JSON API
│       ├── list.php            列表 + 搜索 + 筛选 + 分页
│       ├── detail.php          单首元数据
│       └── download.php        曲谱文件下载（透传到 actions）
│
└── 400.php / 401.php / 403.php / 404.php / 429.php / 500.php / 502.php / 503.php
                                自定义错误页（暗色终端风格）
```

---

## 3. 数据库结构

> **说明**：项目没有统一的初始化 SQL 文件。核心表（users / posts / comments 等）需手动建表，
> `ensure_user_columns()` 在每次请求时自动 ALTER TABLE 补全缺失字段，其余表由代码 `CREATE TABLE IF NOT EXISTS` 自动创建。

### 3.1 表关系概览

```
users ──┬── posts ──┬── comments ── comment_likes
        │           ├── post_likes
        │           ├── post_favs
        │           ├── post_topics ── topics
        │           └── post_view_logs
        │
        ├── follows
        ├── user_blocks
        ├── notifications
        ├── messages
        ├── social_cards
        ├── reports
        └── cs_tickets ── cs_messages
```

---

### 3.2 建表 SQL（完整）

#### users — 用户主表

```sql
CREATE TABLE `users` (
  `id`                   INT          AUTO_INCREMENT PRIMARY KEY,
  `userid`               VARCHAR(50)  NOT NULL UNIQUE,          -- 6位数字登录ID，注册时随机生成
  `mid`                  CHAR(8)      DEFAULT NULL,             -- 8位数字私信ID，用于客服系统
  `email`                VARCHAR(100) NOT NULL UNIQUE,
  `password`             VARCHAR(255) NOT NULL,                 -- bcrypt 哈希
  `username`             VARCHAR(50)  NOT NULL UNIQUE,
  `avatar`               VARCHAR(255) NOT NULL DEFAULT 'default.png',
  `signature`            TEXT         DEFAULT NULL,             -- 个人签名
  `points`               INT          NOT NULL DEFAULT 100,     -- 积分（初始100）
  `role`                 VARCHAR(20)  NOT NULL DEFAULT 'user',  -- user|admin|owner|sponsor|reviewer
  `level`                INT          NOT NULL DEFAULT 1,       -- 等级 1-6，由 add_exp() 维护
  `exp`                  INT          NOT NULL DEFAULT 0,       -- 经验值
  `login_streak`         INT          NOT NULL DEFAULT 0,       -- 连续登录天数
  `last_login_date`      DATE         DEFAULT NULL,
  `email_verified`       TINYINT(1)   NOT NULL DEFAULT 0,
  `verify_token`         VARCHAR(64)  DEFAULT NULL,             -- 邮箱验证 token（24h 有效）
  `verify_token_expires` DATETIME     DEFAULT NULL,
  `verify_resend_at`     DATETIME     DEFAULT NULL,             -- 上次重发验证邮件时间（60s 冷却）
  `reset_token`          VARCHAR(64)  DEFAULT NULL,             -- 密码重置 token（1h 有效）
  `reset_token_expires`  DATETIME     DEFAULT NULL,
  `is_banned`            TINYINT(1)   NOT NULL DEFAULT 0,
  `ban_reason`           VARCHAR(255) DEFAULT NULL,
  `ban_until`            DATETIME     DEFAULT NULL,             -- NULL = 永久封禁；有值 = 限时
  `banned_by`            INT          DEFAULT NULL,             -- 执行封禁的管理员 id
  `show_followers`       TINYINT(1)   NOT NULL DEFAULT 1,       -- 粉丝列表是否公开
  `show_following`       TINYINT(1)   NOT NULL DEFAULT 1,       -- 关注列表是否公开
  `post_visibility`      VARCHAR(20)  NOT NULL DEFAULT 'public',-- 帖子默认可见性
  `is_cs`                TINYINT(1)   NOT NULL DEFAULT 0,       -- 是否为客服人员
  `created_at`           DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> `userid`、`mid` 均为注册时随机生成的唯一数字字符串。`role` 和 `level` 不要手动改——用管理后台或维护 SQL。

---

#### posts — 帖子表

```sql
CREATE TABLE `posts` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT          NOT NULL,
  `title`       VARCHAR(255) NOT NULL DEFAULT '',
  `content`     LONGTEXT     NOT NULL,                          -- WangEditor 输出 HTML
  `status`      VARCHAR(20)  NOT NULL DEFAULT '待审核',         -- 草稿|待审核|已发布
  `is_recommend`TINYINT(1)   NOT NULL DEFAULT 0,               -- 1 = 出现在首页推荐网格
  `is_notice`   TINYINT(1)   NOT NULL DEFAULT 0,               -- 1 = 公告帖，跳过审核
  `category_id` INT          DEFAULT NULL,                     -- 所属分区（NULL = 无分区）
  `repost_id`   INT          DEFAULT NULL,                     -- 转发原帖 ID（NULL = 原创）
  `attachments` TEXT         DEFAULT NULL,                     -- 附件 JSON 字符串
  `views`       INT          NOT NULL DEFAULT 0,               -- 浏览量（去重）
  `approved_by` INT          DEFAULT NULL,                     -- 审核员 user id
  `approved_at` DATETIME     DEFAULT NULL,
  `edited_at`   DATETIME     DEFAULT NULL,                     -- 最后编辑时间（10分钟冷却依据）
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status`      (`status`),
  INDEX `idx_user`        (`user_id`),
  INDEX `idx_category`    (`category_id`),
  INDEX `idx_recommend`   (`is_recommend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### comments — 评论表

```sql
CREATE TABLE `comments` (
  `id`         INT       AUTO_INCREMENT PRIMARY KEY,
  `post_id`    INT       NOT NULL,
  `parent_id`  INT       NOT NULL DEFAULT 0,   -- 0 = 一级评论；非0 = 回复某评论
  `user_id`    INT       NOT NULL,
  `content`    TEXT      NOT NULL,
  `likes`      INT       NOT NULL DEFAULT 0,   -- 点赞数（冗余缓存，由 comment_likes 维护）
  `is_top`     TINYINT(1)NOT NULL DEFAULT 0,   -- 1 = 置顶评论
  `created_at` DATETIME  DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_post` (`post_id`),
  INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### categories — 分区表

```sql
CREATE TABLE `categories` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(50)  NOT NULL,
  `description` VARCHAR(200) NOT NULL DEFAULT '',
  `color`       VARCHAR(7)   NOT NULL DEFAULT '#3fb950',  -- 左侧彩色竖条颜色
  `icon`        VARCHAR(10)  NOT NULL DEFAULT '#',        -- emoji 图标
  `cover_image` VARCHAR(255) NOT NULL DEFAULT '',         -- 封面图路径（必填）
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### follows — 关注关系表

```sql
CREATE TABLE `follows` (
  `id`          INT      AUTO_INCREMENT PRIMARY KEY,
  `follower_id` INT      NOT NULL,   -- 关注者
  `followed_id` INT      NOT NULL,   -- 被关注者
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_follow` (`follower_id`, `followed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### notifications — 站内通知表

```sql
CREATE TABLE `notifications` (
  `id`           INT         AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT         NOT NULL,              -- 接收者
  `from_user_id` INT         DEFAULT NULL,          -- 发送者（系统通知为 NULL）
  `type`         VARCHAR(20) NOT NULL,
  -- type 枚举：comment|reply|mention|like_post|fav_post|like_comment|follow|message|post_review|post_approved|repost
  `post_id`      INT         DEFAULT NULL,
  `comment_id`   INT         DEFAULT NULL,
  `is_read`      TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at`   DATETIME    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### messages — 私信表

```sql
CREATE TABLE `messages` (
  `id`           INT       AUTO_INCREMENT PRIMARY KEY,
  `from_user_id` INT       NOT NULL,
  `to_user_id`   INT       NOT NULL,
  `content`      TEXT      NOT NULL,
  `ref_post_id`  INT       DEFAULT NULL,   -- 转发帖子引用卡片
  `is_read`      TINYINT(1)NOT NULL DEFAULT 0,
  `created_at`   DATETIME  DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_to_user` (`to_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### post_likes — 帖子点赞表

```sql
CREATE TABLE `post_likes` (
  `id`         INT      AUTO_INCREMENT PRIMARY KEY,
  `post_id`    INT      NOT NULL,
  `user_id`    INT      NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_like` (`post_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### post_favs — 帖子收藏表

```sql
CREATE TABLE `post_favs` (
  `id`         INT      AUTO_INCREMENT PRIMARY KEY,
  `post_id`    INT      NOT NULL,
  `user_id`    INT      NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_fav` (`post_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### comment_likes — 评论点赞表

```sql
CREATE TABLE `comment_likes` (
  `id`         INT      AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT      NOT NULL,
  `comment_id` INT      NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_comment_like` (`user_id`, `comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### topics — 话题标签表

```sql
CREATE TABLE `topics` (
  `id`        INT         AUTO_INCREMENT PRIMARY KEY,
  `name`      VARCHAR(50) NOT NULL UNIQUE,   -- 话题名（不含 #）
  `use_count` INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### post_topics — 帖子-话题关联表

```sql
CREATE TABLE `post_topics` (
  `post_id`  INT NOT NULL,
  `topic_id` INT NOT NULL,
  PRIMARY KEY (`post_id`, `topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### user_blocks — 黑名单表（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `user_blocks` (
  `id`         INT      AUTO_INCREMENT PRIMARY KEY,
  `blocker_id` INT      NOT NULL,
  `blocked_id` INT      NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_block` (`blocker_id`, `blocked_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### post_view_logs — 帖子浏览去重日志（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `post_view_logs` (
  `post_id`    INT         NOT NULL,
  `viewer_key` VARCHAR(64) NOT NULL,   -- 登录用户: "u_<id>"；游客: "ip_<sha256>"
  `viewed_at`  DATETIME    NOT NULL,
  PRIMARY KEY (`post_id`, `viewer_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> 同一 `viewer_key` 对同一帖子 24h 内只计一次浏览量。

---

#### reports — 举报表（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `reports` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `reporter_id` INT          NOT NULL,
  `type`        VARCHAR(10)  NOT NULL,      -- post | user
  `target_id`   INT          NOT NULL,
  `reason`      VARCHAR(100) NOT NULL,
  `detail`      VARCHAR(500) DEFAULT NULL,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending|handled|dismissed
  `handler_id`  INT          DEFAULT NULL,
  `handled_at`  DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_type_target` (`type`, `target_id`),
  INDEX `idx_status`      (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### social_cards — 交友卡片表（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `social_cards` (
  `id`         INT          AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT          NOT NULL,
  `gender`     VARCHAR(10)  NOT NULL DEFAULT '',   -- male | female
  `age_range`  VARCHAR(20)  NOT NULL DEFAULT '',   -- 格式：18~25
  `intro`      TEXT         NOT NULL,              -- 最多 300 字
  `tags`       VARCHAR(200) NOT NULL DEFAULT '',   -- JSON：最多4个标签，每个≤10字
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### cs_tickets — 客服工单表（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `cs_tickets` (
  `id`                INT      AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT      NOT NULL,
  `type`              VARCHAR(80) NOT NULL,
  `description`       TEXT     DEFAULT NULL,
  `status`            ENUM('pending','active','resolved') NOT NULL DEFAULT 'pending',
  `assigned_to`       INT      DEFAULT NULL,   -- 分配给的客服 user id
  `last_cs_reply_at`  DATETIME DEFAULT NULL,
  `next_comp_at`      DATETIME DEFAULT NULL,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`       DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### cs_messages — 客服消息表（自动创建）

```sql
CREATE TABLE IF NOT EXISTS `cs_messages` (
  `id`        INT       AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT       NOT NULL,
  `sender_id` INT       NOT NULL,
  `is_cs`     TINYINT(1)NOT NULL DEFAULT 0,   -- 1 = 客服发送，0 = 用户发送
  `content`   TEXT      NOT NULL,
  `created_at`DATETIME  DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.3 自动补全机制

项目使用 `ensure_user_columns($conn)`（在 `includes/exp_helper.php`）在每次请求时检查并补全缺失字段，因此**不需要手动 ALTER TABLE**。首次部署只需建好基础表，访问任意页面即可触发自动补全。

自动管理范围：
- **users 表**：补全 18 个新字段（见 §3.2 users 表字段说明）
- **posts 表**：补全 `views`、`repost_id` 列
- **messages 表**：补全 `ref_post_id` 列
- **自动建表**：`user_blocks`、`post_view_logs`、`reports`
- **cs 表**由 `pages/cs_chat.php` 首次访问时创建

---

## 4. 模块说明

### 认证流程（actions/auth.php）

```
注册 → 创建 users 记录 → EMAIL_VERIFY_REQUIRED?
       ├─ true  → 发验证邮件 → 用户点链接 → verify.php → email_verified=1
       └─ false → 直接 email_verified=1 → 跳登录页

登录 → 密码验证 → 封禁到期检查（自动解封）→ 邮箱验证检查 → 写 session → 登录经验奖励
```

### 发帖流程（actions/save.php）

```
publish.php 填写 → AJAX POST save.php
                    ├─ is_draft=1  → status='草稿'
                    ├─ is_notice=1 → status='已发布'（admin/owner 跳过审核）
                    └─ 普通帖子   → status='待审核' → 通知所有 admin/owner
                                                      → admin.php 审核通过后发布
```

### 通知类型速查

| type | 触发时机 |
|------|----------|
| `comment` | 有人评论你的帖子 |
| `reply` | 有人回复你的评论 |
| `mention` | 评论中 @了你 |
| `like_post` | 有人点赞你的帖子 |
| `fav_post` | 有人收藏你的帖子 |
| `like_comment` | 有人点赞你的评论 |
| `follow` | 有人关注了你 |
| `message` | 有人发私信给你 |
| `post_review` | 帖子提交审核（通知管理员） |
| `post_approved` | 帖子审核通过（通知作者） |
| `repost` | 有人转发了你的帖子 |

### 帖子可见性

| 值 | 说明 |
|----|------|
| `public` | 所有人可见（默认） |
| `followers` | 仅关注了我的人可见 |
| `following` | 仅我关注的人可见 |
| `mutual` | 仅互相关注可见 |
| `private` | 仅自己可见 |

> admin / owner 绕过所有可见性限制。检查函数 `can_see_posts()` 在 `includes/exp_helper.php`。

---

## 5. 角色与权限

| role | 颜色 | 标签 | 可封禁 | 管理权限 |
|------|------|------|--------|---------|
| `owner` | `#f85149` 红 | ★ 站长 | admin / user | 全部 |
| `admin` | `#a78bfa` 紫 | 管理员 | user | 审核 / 封禁 / 分区 / 举报 |
| `reviewer` | `#e3b341` 金 | ◈ 审核员 | — | 仅审核帖子 |
| `sponsor` | `#c084fc` 发光紫 | 赞助者 | — | — |
| `user` | `#58a6ff` 蓝 | 成员 | — | — |
| （封禁） | `#6e7681` 灰 | 已封禁 | — | — |

角色徽章由 `get_role_badge()` / `get_role_info()` 统一输出，不要在其他地方硬编码颜色。

---

## 6. 经验与等级

### 等级阈值

| 等级 | 名称 | 所需总 EXP | 颜色 |
|------|------|-----------|------|
| Lv1 | 新手 | 0 | `#8b949e` 灰 |
| Lv2 | 学徒 | 1,000 | `#58a6ff` 蓝 |
| Lv3 | 成员 | 5,000 | `#3fb950` 绿 |
| Lv4 | 精英 | 15,000 | `#d29922` 金 |
| Lv5 | 大师 | 30,000 | `#f0883e` 橙 |
| Lv6 | 传奇 | 50,000 | `#f85149` 红 |

### 每日登录奖励

公式：`min(连续天数 × 10, 100)` EXP（第1天=10，第10天及以上=100，中断归零）

### 核心函数（includes/exp_helper.php）

| 函数 | 说明 |
|------|------|
| `add_exp($conn, $uid, $amount)` | 加经验并自动更新 level，返回 `['exp', 'level']` |
| `get_level_by_exp($exp)` | EXP → 等级数字 1-6 |
| `calc_login_exp($streak)` | 连续天数 → 当日奖励 EXP |
| `get_level_name($level)` | 等级 → 名称字符串 |
| `get_level_color($level)` | 等级 → 颜色十六进制 |

---

## 7. 封禁系统

### 封禁类型

| ban_until | 类型 |
|-----------|------|
| `NULL` | 永久封禁 |
| 未来时间 | 限时封禁，到期由 header.php 自动解封 |

### 拦截点

被封用户仍可登录、浏览帖子、联系客服，以下操作全部拦截：

| 位置 | 拦截内容 |
|------|---------|
| `includes/header.php` | 每次页面加载刷新封禁状态，到期自动解封；显示封禁横幅 |
| `actions/comment_save.php` | 评论 |
| `actions/post_action.php` | 点赞 / 收藏 |
| `actions/follow_toggle.php` | 关注 |
| `actions/message_send.php` | 发私信 |
| `actions/save.php` | 发帖 |
| `actions/social_card_save.php` | 投放交友卡片 |
| `pages/publish.php` | 访问发帖页 |
| `pages/edit_profile.php` | 访问编辑资料 |
| `pages/settings.php` | 访问设置页 |

### 封禁管理入口

- 后台：`pages/admin_ban.php`（admin/owner）
- action：`actions/ban_user.php`（POST: action=ban|unban, user_id, reason, ban_until）

---

## 8. 举报系统

| 组件 | 说明 |
|------|------|
| `pages/report_modal.php` | 举报弹窗（post.php + profile.php include） |
| `actions/report_submit.php` | 提交端点（POST: type=post|user, target_id, reason, detail） |
| `pages/admin_reports.php` | 处理后台：删帖 / 封号 / 驳回 / 标记已处理 |

导航抽屉红点 = 待审核帖子数 + 待处理举报数（分开计数）。

---

## 9. 转发与动态

### 转发

- `posts.repost_id`：转发帖指向原帖 ID，允许空标题 / 内容
- `messages.ref_post_id`：私信中携带的帖子预览卡片
- `includes/repost_card.php`：`render_repost_card($conn, $post_id, $base)` 渲染 HTML

### 动态页（pages/moments.php）

展示当前用户 + 已关注者的帖子（含转发），按时间倒序分页，导航栏"动态"入口（登录可见）。

---

## 10. 交友页

入口：`dating.php`

| 要素 | 说明 |
|------|------|
| 卡片墙 | 6列，奇列向上滚动 / 偶列向下滚动，时长 58-80s |
| 卡片样式 | 5:7 比例（扑克牌），背景为头像模糊处理，性别光晕（女=粉红 / 男=蓝） |
| 投放条件 | 性别（必填）+ 年龄段（≥18，格式 20~25）+ 介绍（≤300字）+ 最多4个标签（每个≤10字） |
| 过滤 | `is_active=1` + 双向黑名单 |
| 弹窗 | 点击卡片查看详情、发私信；admin/owner 可删除他人卡片 |

---

## 11. 客服系统

| 文件 | 说明 |
|------|------|
| `pages/cs_chat.php` | 用户端聊天页，发起 / 查看工单 |
| `pages/cs_panel.php` | 客服工作台，管理多用户工单（is_cs=1 或 admin/owner） |
| `actions/cs_action.php` | action 端点 |

设置 `users.is_cs = 1` 赋予客服权限，`is_cs = 0` 撤销。

---

## 12. 在线曲库（云端曲谱）

为 SkyMusic 桌面端（光遇自动弹琴助手）提供共享曲谱的云端存储与分发。匿名可下载，登录可上传。完全独立于帖子/分区系统。

### 核心文件

| 文件 | 说明 |
|------|------|
| `sheets.php` | 曲库主页（公开浏览，搜索/排序/难度筛选/分页） |
| `pages/sheet_detail.php` | 详情页（元数据 + 下载 + 点赞 + 删除） |
| `pages/sheet_upload.php` | 上传页（登录+未封禁，前端 JS 自动解析 JSON 填表） |
| `pages/admin_sheets.php` | 管理后台（精选切换 / 下架 / 恢复 / 硬删） |
| `actions/sheet_upload.php` | 接收上传，解析元数据写库写文件 |
| `actions/sheet_download.php` | 公开匿名下载，downloads++ 后流式返回 |
| `actions/sheet_like.php` | 点赞 toggle |
| `actions/sheet_delete.php` | 自删 / 管理员强删（同时 unlink 文件） |
| `api/sheets/list.php` | 公开 JSON API：列表 + 搜索 + 筛选 + 分页 |
| `api/sheets/detail.php` | 公开 JSON API：单首元数据 |
| `api/sheets/download.php` | 公开 API：直接返回曲谱文件（透传到 actions） |

### 数据表

#### song_sheets — 曲谱主表

```sql
CREATE TABLE song_sheets (
  id                INT          AUTO_INCREMENT PRIMARY KEY,
  uploader_id       INT          NOT NULL,
  title             VARCHAR(150) NOT NULL,
  artist            VARCHAR(100) DEFAULT NULL,                  -- 原唱 / 作曲
  transcribed_by    VARCHAR(100) DEFAULT NULL,                  -- 创谱人
  bpm               INT          DEFAULT NULL,
  subdiv            INT          DEFAULT NULL,                  -- 每拍细分（1/N）
  difficulty        TINYINT      NOT NULL DEFAULT 3,            -- 1-5 星
  description       VARCHAR(500) DEFAULT NULL,
  tags              VARCHAR(255) DEFAULT NULL,                  -- 逗号分隔
  file_path         VARCHAR(255) NOT NULL,                      -- uploads/sheets/xxx.txt
  original_filename VARCHAR(255) DEFAULT NULL,
  file_size         INT          NOT NULL DEFAULT 0,
  note_count        INT          NOT NULL DEFAULT 0,
  downloads         INT          NOT NULL DEFAULT 0,
  likes             INT          NOT NULL DEFAULT 0,
  views             INT          NOT NULL DEFAULT 0,
  status            VARCHAR(20)  NOT NULL DEFAULT 'published',  -- published|rejected
  is_recommended    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_uploader  (uploader_id),
  INDEX idx_status    (status),
  INDEX idx_created   (created_at),
  INDEX idx_downloads (downloads),
  INDEX idx_likes     (likes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### sheet_likes — 点赞关系表

```sql
CREATE TABLE sheet_likes (
  sheet_id   INT      NOT NULL,
  user_id    INT      NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sheet_user (sheet_id, user_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> 表由 `ensure_song_sheets_table($conn)` 自动创建（在 `includes/exp_helper.php`）。曲谱文件存放路径 `uploads/sheets/`，目录由首次调用时 mkdir。

### 关键约束

- **文件类型**：白名单 `.txt` / `.json`
- **大小上限**：5MB
- **删除策略**：硬删（DB 删行 + `unlink($file_path)`，连带 `sheet_likes`）
- **编码兼容**：`normalize_sheet_encoding()` 处理 UTF-16 LE/BE BOM 和 UTF-8 BOM；落盘统一为 UTF-8
- **匿名下载**：`actions/sheet_download.php` 不需要 session
- **审核策略**：默认 `status='published'` 后审制（复用举报系统）

### 客户端集成

SkyMusic 桌面端（[Sky-Automatic-Piano-Assistant](https://github.com/lingyunalingyun/Sky-Automatic-Piano-Assistant)）有 `CloudSheetsWindow.java`，调本站 `api/sheets/list.php` + `actions/sheet_download.php` 实现「☁ 在线曲库」功能。

---

## 13. 配置说明

### config.php 常量

| 常量 | 说明 |
|------|------|
| `SITE_URL` | 站点根 URL（无末尾斜杠），用于邮件链接拼接 |
| `SITE_NAME` | 站点名称，显示在邮件头和页面标题 |
| `MAIL_FROM` | 发件人邮箱，需 PHP `mail()` 可用 |
| `EMAIL_VERIFY_REQUIRED` | `true` = 注册需验证邮箱；`false` = 直接激活（当前 false） |

### 上传目录权限

```
uploads/avatars/        chmod 755 或 777
uploads/posts/
uploads/attachments/
uploads/categories/
```

---

## 14. 开发工作流

```
1. 在 论坛开发_本地版 修改并本地测试

2. 将修改文件复制到 论坛开发_上传版
   - config.php 不复制（上传版已脱敏）
   - 为新增文件补充 docblock 注释

3. 检查：
   - 无 var_dump / print_r / die("调试") 等残留
   - 无硬编码密码或真实域名

4. git add → git commit → git push

5. 服务器 git pull
```

---

## 15. 常见维护操作

### 设置角色

```sql
UPDATE users SET role = 'admin'    WHERE username = '用户名';
UPDATE users SET role = 'owner'    WHERE username = '用户名';
UPDATE users SET role = 'sponsor'  WHERE username = '用户名';
UPDATE users SET role = 'reviewer' WHERE username = '用户名';
UPDATE users SET role = 'user'     WHERE username = '用户名';  -- 降权
```

### 手动封禁 / 解封

```sql
-- 永久封禁
UPDATE users SET is_banned=1, ban_reason='原因', ban_until=NULL  WHERE username='用户名';
-- 限时7天
UPDATE users SET is_banned=1, ban_reason='原因', ban_until=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE username='用户名';
-- 解封
UPDATE users SET is_banned=0, ban_reason=NULL, ban_until=NULL, banned_by=NULL WHERE username='用户名';
```

### 设置客服

```sql
UPDATE users SET is_cs = 1 WHERE username = '用户名';
UPDATE users SET is_cs = 0 WHERE username = '用户名';  -- 撤销
```

### 推荐 / 取消推荐帖子

```sql
UPDATE posts SET is_recommend = 1 WHERE id = 帖子ID;
UPDATE posts SET is_recommend = 0 WHERE id = 帖子ID;
```

### 手动发布待审核帖子

```sql
UPDATE posts SET status='已发布', approved_at=NOW() WHERE id = 帖子ID;
```

### 清理孤立数据

```sql
DELETE FROM comments     WHERE post_id NOT IN (SELECT id FROM posts);
DELETE FROM post_likes   WHERE post_id NOT IN (SELECT id FROM posts);
DELETE FROM post_favs    WHERE post_id NOT IN (SELECT id FROM posts);
DELETE FROM post_topics  WHERE post_id NOT IN (SELECT id FROM posts);
DELETE FROM notifications WHERE post_id IS NOT NULL
  AND post_id NOT IN (SELECT id FROM posts);
```

### 开启邮箱验证

```php
// config.php
define('EMAIL_VERIFY_REQUIRED', true);
```

---

## 16. 部署注意事项

1. **PHP 版本**：服务器为 PHP 7.x，禁用 `match()` 表达式，代码已用数组映射替代。

2. **MySQL 版本**：不支持 `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`，`ensure_user_columns()` 用 `SHOW COLUMNS` 兼容。

3. **建表顺序**：先建 `users`、`posts`、`comments`、`categories`、`follows`、`notifications`、`messages`、`post_likes`、`post_favs`、`comment_likes`、`topics`、`post_topics`，其余表由代码自动创建。

4. **首次部署激活 ensure_user_columns**：访问任意调用了该函数的页面（如 `profile.php`）即可自动补全所有缺失字段。

5. **uploads/ 目录**：需手动创建四个子目录并设置写权限（见 §12）。

6. **错误页路由**（Apache）：

   ```apache
   ErrorDocument 400 /400.php
   ErrorDocument 401 /401.php
   ErrorDocument 403 /403.php
   ErrorDocument 404 /404.php
   ErrorDocument 429 /429.php
   ErrorDocument 500 /500.php
   ErrorDocument 502 /502.php
   ErrorDocument 503 /503.php
   ```

7. **config.php 必填常量**：`SITE_URL`、`SITE_NAME`、`MAIL_FROM`、`EMAIL_VERIFY_REQUIRED`，缺任意一项邮件功能异常。
