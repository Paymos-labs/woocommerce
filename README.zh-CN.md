# Paymos for WooCommerce（官方稳定币支付插件）

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 WooCommerce 支付网关插件。为您的独立站结账页接入 USDT/USDC 等稳定币收款方式，买家跳转至 Paymos 托管收银台完成转账，订单状态通过带 HMAC-SHA256 签名的 Webhook 自动更新。买家可从您的 Paymos 项目启用的币种与公链网络中自由选择。订单金额按商店原生法币计价，汇率在买家付款时锁定，实际入账金额与开票金额完全一致。

订单生命周期不依赖买家是否返回站点：无论关闭标签页、手机没电还是浏览器崩溃，只要 Webhook 送达，订单状态都会准确同步。

[![观看视频演示：Paymos 12 款官方 CMS 稳定币支付插件 — 一键连接免复制密钥](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **产品演示视频（1:12，内置 6 种语言字幕）：** [在 YouTube 观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- WordPress 6.5 及以上版本（已测试至 7.0）；
- WooCommerce 8.0 及以上版本（已测试至 10.9）；
- PHP 7.4 及以上版本，需启用 `curl`、`hash`、`json` 和 `openssl` 扩展；
- 公网可访问的 HTTPS 商店地址（用于接收 Webhook 回调）；
- 已在 Paymos 控制台打开目标收款项目的账号。

安装包已内置 Paymos PHP SDK，商店服务器上无需安装或运行 Composer。已声明兼容 WooCommerce 高性能订单存储（HPOS）与结账区块（Checkout Blocks）。

## 安装与一键连接

1. 从 [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest) 或 Paymos 控制台中的 **CMS 集成** 面板下载最新官方安装包。
2. 在 WordPress 后台进入 **插件 → 安装新插件 → 上传插件**，上传并启用。
3. 打开 **WooCommerce → 设置 → 付款 → Paymos**。
4. 点击 **Connect Paymos**，浏览器将在新标签页打开 Paymos 授权页。
5. Paymos 会自动使用您当前在控制台选中的项目：分别针对 Sandbox（沙盒）与 Live（生产）环境复用商户唯一有效的 Payment 密钥（若尚未创建则自动生成），并复用完全匹配的 Invoice Webhook 或为当前商店地址创建专用 Webhook。
6. 返回 WooCommerce 设置页，选择 Sandbox 或 Live 模式即可开始使用。

所有商户下载的插件包完全一致，内部不包含任何凭证——没有 API Key、API Secret、Project ID、Webhook Secret、OAuth Token 或设备码。仅下载安装不会建立连接；第 4 步的授权确认才是真正完成商店配置的关键步骤。

WooCommerce 后台不需要手动粘贴密钥，也没有二次项目选择器。一次性设备授权响应会严格校验插件类型和商店 URL，随后以 AES-256-GCM 加密写入非自动加载（non-autoloaded）的 WordPress 选项，短期 OAuth Token 立即销毁。此后所有 Merchant API 调用均采用 HMAC 签名。已保存的密钥永远不会回显到设置页 HTML，也无法手动输入——如轮换密钥或 Webhook Secret，重新连接即可。

系统会自动注册以下 Webhook 地址，并在设置页以只读方式展示：

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

## 订单状态流转

买家下单后会创建 Paymos 账单、清空购物车并跳转至托管收银台。跳转前订单会先置于 **保留（On hold）** 状态并记录备注 *Awaiting Paymos payment*，避免订单处于不确定状态。

| 收到的事件 | WooCommerce 订单状态变化 |
|---|---|
| `invoice.confirming` | 保留（On hold）— 链上转账已广播，正在等待区块确认 |
| `invoice.underpaid_waiting` | 保留（On hold），备注会写明仍差额多少未付清 |
| `invoice.awaiting_payment` | 保留（On hold）— 已计入的转账因链上重组（reorg）回滚 |
| `invoice.paid`, `invoice.paid_over` | 调用 `payment_complete()` — 由 WooCommerce 自身规则决定转为「处理中（Processing）」，或对纯虚拟可下载商品直接转为「已完成（Completed）」 |
| `invoice.underpaid` | 失败（Failed） |
| `invoice.expired`, `invoice.cancelled` | 已取消（Cancelled） |

状态映射前有两重安全护栏：已支付的订单绝不会被迟到或乱序的事件降级（过期事件仅写入备注并丢弃）；如果收到的已支付事件金额与当前订单总额不再一致，订单会保持 **保留（On hold）** 并标记 *needs manual review* 而不是自动完成，防止订单金额被修改后被静默结算。

订单完成后，链上交易哈希与区块浏览器链接会写入订单元数据，并展示在感谢页、客户邮件以及后台订单页的 **Paymos Payment** 面板中（同时展示账单 ID、金额快照以及最近一次事件的 ID 与时间戳）。

## 先在沙盒环境测试

1. 将 **Mode** 保持为 Sandbox，启用支付方式并在您的商店下一笔测试订单。
2. 在 Paymos 控制台打开对应账单。沙盒付款不会按倒计时自动确认——您可以手动选择任意结果（已支付、多付、少付、已取消），系统会触发与主网完全一致的生命周期事件。
3. 观察 WooCommerce 订单状态变化，并在订单备注中查看完整事件轨迹。
4. 确认流程无误后将 **Mode** 切换为 Live。一次授权同时覆盖两个环境，无需重新连接或重复配置。

沙盒转账没有真实链上记录，因此交易哈希和区块浏览器链接为空属于正常现象，并非映射异常。

## Webhook 验签与定时对账

每次回调均附带 `X-Webhook-Signature`（`t={timestamp},v1={hmac_hex}`，采用 HMAC-SHA256 与常量时间比对，并在密钥轮换期间保留宽限窗口）。每个事件都有稳定的 `X-Webhook-Id` 并由插件记录去重，重复投递只会确认接收而不会重复执行。

在写入任何终态之前，插件都会向 Merchant API 回查该账单并复核，确保伪造或重放的请求体无法单独触发订单完成。

内置的 WP-Cron 任务（`paymos_reconcile_orders`）每 10 分钟运行一次作为账单兜底对账：每次拉取最多 50 笔仍处于待付款/保留状态的 Paymos 订单，以及最多 50 笔账单尚未进入终态的失败/已取消订单，从 API 获取最新状态并走与 Webhook 完全相同的状态映射和护栏逻辑。另一个每分钟运行的 WP-Cron 任务（`paymos_woocommerce_refunds_reconcile`）负责同步处理中的退款。

## 退款

插件通过 Paymos Merchant API（`/v1/refunds`）提供原生退款能力，支持两种由服务端自动计算金额的模式：`full`（全额退款）与 `overpayment`（对 `paid_over` 多付账单退还溢缴部分）。Paymos 会发起一笔新的出链转账，严格退回至已支付账单记录并验证过的 `first_sender` 原付款地址——无需手动输入收款钱包地址，不支持任意自定义金额的部分退款。

管理员可在 WooCommerce 订单编辑页通过两种方式发起退款：

- 使用订单商品区域的 WooCommerce 原生 **退款（Refund）** 按钮（提交 `full` 全额退款请求）；
- 使用订单页中的 **Paymos Payment** 退款面板（`woocommerce_admin_order_data_after_order_details`），同时支持 `full` 与 `overpayment` 模式，并展示已验证的 `first_sender` 收款地址与当前退款进度。

链上退款仍在处理中时，WooCommerce 不会提前入账本地 `WC_Order_Refund`。插件通过 `/wp-json/paymos/v1/webhook` 签名回调与每分钟执行的 `paymos_woocommerce_refunds_reconcile` 任务向 Merchant API 核验状态，待退款达到 `completed` 后才正式记录 WooCommerce 订单退款（`overpayment` 模式记录订单备注）。在 Sandbox 模式下可模拟 `completed`、`failed`、`cancelled` 等终态以验证退款流程。

## 常见问题排查

**订单一直停留在「保留（On hold）」。** 商店必须可从公网访问才能收到 Webhook；处于 HTTP 认证或内网环境下的测试站无法接收回调。请确认上述 Webhook URL 可正常响应，并在网关设置中开启 **Debug logging** 后查看 **WooCommerce → 状态 → 日志**。

**链上已支付，但订单提示金额需要人工复核。** 账单创建后订单总额发生了变化。请将 **Paymos Payment** 面板中的金额快照与当前订单总额核对后手动处理——插件不会自动完成无法对平金额的订单。

**结账页不显示 Paymos 支付方式。** 当当前所选环境未完整连接时，插件会自动隐藏。请进入网关设置查看 **Connection status** 表格；若任意一项显示 *Missing*，说明该环境尚未完成授权连接。

- [官方文档](https://paymos.io/zh-hans/docs/cms-woocommerce)
- [源代码](https://github.com/paymos-labs/woocommerce)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
