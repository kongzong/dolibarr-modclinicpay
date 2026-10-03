# modClinicPay — 诊所收费与次卡

面向中医馆/中西医结合诊所的收费结算模块：一张收费单在确认时于**同一事务**内创建原生发票（Facture）并登记收款（现金/扫码）；次卡/疗程卡为预付资产，售卖与充值开收费单（生成发票），消费时原子核销次数或余额（不生成发票）；退费走独立审批（红字发票冲销原票）。

规格：`docs/spec-clinicpay-v0.1.md`（2026-09-22 评审通过）。上位规划：`../HEALTHCARE-MODULES-PLAN.md` §3.4。

## 当前状态：V0.1.0 完成（阶段 1–4 全部交付，未推送）

已实现：

- **阶段 1 骨架**：descriptor（模块 ID **501640**、`models=1`、权限 read/write/pay/validate/consume/admin）、4 表 + 2 sequence、常量、"诊所"左菜单、患者卡片 Tab、双语言
- **阶段 2 收费核心**：
  - `Paybill::create()`：草稿建单，产品现价快照，金额全部经 `calcul_price_total()`，`SF-YYYYMMDD-NNN` 编号与落库同事务（`PaybillNumbering` 行锁方案）
  - `Paybill::confirm()`：幂等闸门（`UPDATE ... WHERE status=0` 行数闸门）+ 原生 Facture（TYPE_STANDARD，行快照）+ `validate()` + 原生 `Paiement`（CASH→CASH / SCAN→CB，扫码必填流水号）+ 发票 rowid 回写 + 审计，任一步失败整体回滚（无发票则无收费单）
  - 退费两步：`createRefundDraft()`（write，生成红字发票草稿 TYPE_CREDIT_NOTE + `fk_facture_source` 指向原票）→ `executeRefund()`（validate，双人红线：创建人与执行人不得同人，admin 除外；红票 validate + 负额退款流水 + 状态 9）；原票已作废/已全额红冲拒绝
  - 收费页 `bill.php`（建单编辑器 / 确认收费 / 退费入口）、列表通道列、设置页银行账户与编号预览
  - 测试：`tests/run_all.php` 结构测试；`tests/integration/`（`bill_step.php` + `run_phase2.sh`）真库并发验收——20 进程并发确认同一草稿仅生成 1 张发票，退费双人红线实测拦截

## 安装

1. 目录放入 `htdocs/custom/clinicpay/`，依赖 modPatient（≥0.1.3）、modFacture、modBanque 已启用
2. 后台模块页启用 modClinicPay（自动建表、注册权限/菜单/患者 Tab）

## 测试

```bash
# 结构测试（22 项）
/d/dolibarr/bin/php/php7.4.26/php.exe tests/run_all.php

# 集成测试（真库并发 + 退费双人红线；Git Bash）
cd tests/integration && PHP=/d/dolibarr/bin/php/php7.4.26/php.exe bash run_phase2.sh

# 次卡集成测试（售卖/充值/并发核销/过期/退卡双人）
cd tests/integration && PHP=/d/dolibarr/bin/php/php7.4.26/php.exe bash run_phase3.sh

# PDF 验收（pdf_sf 三版式，pypdf 提取文本校验）
/d/dolibarr/bin/php/php7.4.26/php.exe tests/integration/pdf.php

# REST API 集成（8 端点 + 403/409 矩阵 + 幂等确认 + 双人红线）
/d/dolibarr/bin/php/php7.4.26/php.exe tests/integration/api.php

# 停用→启用全流程（数据/权限/常量无损）
/d/dolibarr/bin/php/php7.4.26/php.exe tests/integration/enable_disable.php
```

## 次卡（阶段 3 已完成，真库验证）

- 售卖/充值走收费单（同事务：bill + 原生发票 + 收款 + 卡 + CREATE/CHARGE 流水），需 `write`+`pay`
- 核销不生成发票（卡已付费），原子条件 UPDATE 闸门防并发超扣，需 `consume` 权限
- 过期按 `date_end + CLINICPAY_CARD_GRACE_DAYS` 判定，读取时只展示；首次核销尝试懒写 `EXPIRE` 流水并置状态 2
- 退卡 = 售卖票红冲（复用退费双人两步），成功后卡清零（状态 3）+ `REFUND` 流水；V0.1 仅支持未充值过的卡（多充值卡逐票退费）
- 卡号 `CK-YYYYMM-NNNN`（跨月流水），行锁方案
- 退卡（V0.2）：按剩余额度红冲售卖/充值发票（比例分摊），多充值卡支持；剩余为 0 拒绝（`ClinicPayErrCardNothingToRefund` 保护）

## 收费单 PDF 与 REST API（阶段 4 已完成）

- **收费单 PDF `pdf_sf`**（A4，`stsongstdlight`）：机构名/编号/患者/通道表头，明细表（数量/单价/税/小计），合计（数字 + GB/T 15835 大写，`clinicpay_amount_to_chinese()`），收银员/患者签字位；已退费票带红色"已退费"水印。确认收费后自动生成（失败不影响收费），`pdf.php?id=` 在线查看（`?regen=1` 强制重建），每次下载审计 `CLINICPAY_PRINT`
- **REST API**（URL `/api/index.php/clinicpay/...`，API 类 `Clinicpay`，逐端点权限校验）：

| 方法 | 端点 | 权限 |
|---|---|---|
| GET | `bills?q&patient&status&from&to` | read |
| GET | `bills/{id}`（含行，审计 CLINICPAY_READ） | read |
| POST | `bills`（草稿建单） | write |
| POST | `bills/{id}/confirm`（幂等；发票失败→409） | pay |
| POST | `bills/{id}/refund`（默认建退费草稿；`"execute": true` 执行） | write / validate |
| GET | `cards?q&patient&status&card_type` | read |
| GET | `cards/{id}`（含流水） | read |
| POST | `cards`（售卖=同事务建收费单+发票+收款） | write + pay |
| POST | `cards/{id}/consume`（超扣/用尽/过期→409） | consume |

  卡退卡走 bill 退费流（无独立端点）。所有端点不输出患者证件号。

## 税票号登记（fapiao_no，2026-10-03）

中国的「发票」指税务发票（增值税发票/数电票），**只能由税控或电子税务平台开具**，本项目**明确不接税控**。因此这里只做**登记回填**：

- 字段 `llx_clinicpay_bill.fapiao_no varchar(64)`，**挂在业务收费单上，不动 `llx_facture`** —— 后者是 Dolibarr 内部商业账单（应收/收款凭证），与税务发票不是一回事，混在一起会让两层语义都变脏
- 语义：财务在税控系统开票后，把票号填回本单，供业务单据 ↔ 税票人工对账
- 入口：收费单详情页（已收费/已退款单，`clinicpay.write` 权限）行内登记/修改；空值提交即清除
- 草稿单禁止登记（税票在收款后开具），类方法 `setFapiaoNo()` 返回 `ClinicPayErrFapiaoDraft`
- 留痕：`patient_audit` 记 `CLINICPAY_BILL / op=fapiao`（含票号）
- 列表面板新增「税票号」列，未登记显示 `-`
- 老库升级：`scripts/ensure_clinicpay_fapiao.php`（幂等 ALTER，seed 已自动调用）

## 红线（摘要，全文见 spec §5）

1. 金额全部经 `calcul_price_total()`/`price2num()`；行小计与总额快照落库
2. 确认收费 = 幂等闸门 + 发票创建/validate + 收款登记同事务，失败整体回滚
3. 卡核销用条件 UPDATE 行数闸门防并发超扣；卡流水只插不改不删
4. 退费创建（write）与执行（validate）权限分离且不得同人（admin 除外）
5. 收费单/卡/流水不可物理删除；审计 `CLINICPAY_*` 经 `patient_audit()`
6. 页面/PDF/REST 永不输出患者证件号
