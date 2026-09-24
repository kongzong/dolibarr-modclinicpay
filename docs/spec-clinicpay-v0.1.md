# modClinicPay V0.1 规格 — 收费结算与次卡

> 状态：**已评审通过**（评审通过 2026-09-22，动工排在 modPharmacy 阶段 1-2 之后）。医疗模块群二期第二个模块（规划稿 §3.4，清单 #4）。
> 依赖 modPatient ≥ 0.1.3（患者/摘要/审计/上下文条）；发票/付款用 Dolibarr 原生 Facture/Paiement，零 core 修改。
> 上位规划：`custom/HEALTHCARE-MODULES-PLAN.md` §3.4；技术约定：`custom/DOLIBARR-MODULE-DEVELOPMENT.md`。
> 立项答案沿用：中医馆/中西医结合、单机构、**非医保纯自费**（医保通道从路线移除，规划 §7.4 已闭合）。

## 0. 相对规划稿的调整

| 项 | 规划稿 | 本规格 | 理由 |
|---|---|---|---|
| 发票生成 | "收费单 → 自动生成原生发票（Trigger）" | 收费单**确认时**在**同一事务**内创建原生 Facture（直接调用，不走 Trigger） | Trigger 模式要求先落库再生成，失败时收费单已成——同一事务直接调用才能保证"没有发票就没有收费单" |
| 次卡扣次 | "核销流水" | 与本规格一致，但**卡核销与收费单解耦**：就诊消费可挂卡（免现金路径），卡售卖/充值开收费单 | 挂卡核销不是收费动作，不该生成发票；售卖/充值才是 |
| 退费 | "红字发票 + 卡退回" | 保留；退费单独立审批（`validate` 权限）后执行 | 规划红线"双人权限或管理员"落地为：创建退费（write）与执行退费（validate）两权限 |
| 结算通道 | "接口化，现金/扫码 V1" | V1：现金/扫码（手工登记流水号）；通道类预留 `PayChannel` 接口位 | 微信支付需备案域名与商户资质（同 wecom 联调，远期） |

## 1. 定位与已核实地基

一张收费单 = 前台把一次就诊的消费（挂号/诊金/药品/治疗/材料，逐项映射产品/服务）汇总收银，
确认后**同事务**生成一张原生发票（Facture，类型标准）并登记收款（现金/扫码）；
次卡/疗程卡是预付资产：售卖与充值开收费单（生成发票），消费时核销次数/余额（不生成发票）；
退费走独立审批：红字发票（`Facture::TYPE_CREDIT_NOTE`）冲销原票 + 原路退回登记。

本机 22.0.4 源码已核实（2026-09-22）：

| 依赖 | 出处 | 结论 |
|---|---|---|
| 发票创建 | `facture.class.php:461` `Facture::create(User $user, ...)`；`TYPE_STANDARD=0`、`TYPE_CREDIT_NOTE=2`（facture.class.php:389 附近常量） | 同事务调用；`fk_soc` = 患者个人第三方；行用 `addline()` |
| 金额计算 | `price.lib.php:90` `calcul_price_total($qty, $pu, $remise_percent_ligne, $txtva, ...)` | 全部金额经此函数，精度红线 |
| 发票核验/付款 | `Facture::validate()`、`Paiement` 类（compta/paiement/class/payment.class.php） | 收款登记走原生；V1 收费单确认即 `validate()` 发票 |
| 红字发票 | `Facture` `TYPE_CREDIT_NOTE` + `fk_facture_source`；`deletable()`/`validate()` | 退费对原票开负数行红票，`fk_facture_source` 指向原票 |
| 产品/服务 | `Product`（type 0 产品/1 服务）、价格 `price` 字段与 `price2num()` | 收费项目映射产品/服务，价格取产品现价快照 |
| 患者/审计/摘要 | `patient_audit()`、`patient_get_summary()`、患者上下文条 | 审计动作前缀 `CLINICPAY_*`；审计页自动收录 |
| 编号 | 行锁方案（patient/medrecord/prescription 同构） | 收费单 `SF-YYYYMMDD-NNN`、次卡号 `CK-YYYYMM-NNNN` |
| REST | prescription spec §3.7 "REST 命名约束" | URL `/clinicpay/...` → API 类 `Clinicpay`；业务类 `Paybill`/`ServiceCard` 让位命名 |
| 上下文条挂载 | `patient_summary_banner()` + `complete_head_from_modules('patient')` | 收费页从患者卡片"收费"Tab 进入，预填患者 |

## 2. 前置改动（独立提交）

- **modPatient 0.1.4**：患者卡片 Tab 描述符数组允许其他模块以 `patient:+clinicpay:...` 追加"收费"Tab（0.1.1 机制已支持，核对无改动则跳过本项）。
- **modPrescription**：无改动。处方金额仍不算（prescription spec §0 第 5 行决议不变）；收费项目由前台逐项添加，药品行可从已发药处方带入（读 `llx_pharmacy_dispense_line`，pharmacy 0.1.0 就绪后接；V0.1 先手工）。

## 3. 功能范围（做）

### 3.1 数据

- `llx_clinicpay_bill`（收费单）：`rowid, entity, ref(唯一 SF-YYYYMMDD-NNN), fk_patient, fk_invoice(原生发票 rowid, 可空直到确认), status(0草稿/1已收费/9已退费), amount_total(decimal 24,8 经 calcul_price_total), channel(varchar 16: CASH/SCAN), channel_ref(扫码流水号), fk_user_pay, date_pay, note, model_pdf, fk_user_creat, date_creation, tms`
- `llx_clinicpay_bill_line`：`rowid, fk_bill, position, fk_product(可空), product_ref, label(快照), qty, price_unit(快照), vat_rate, subprice_total(行小计), fk_prescription(可空：药品来源处方), fk_dispense(可空：来源发药单)`
- `llx_clinicpay_card`（次卡/疗程卡）：`rowid, entity, ref(唯一 CK-YYYYMM-NNNN), fk_patient, fk_product(卡对应产品/服务), card_type(COUNT 次数/VALUE 储值), total_count, used_count, total_value, used_value, date_start, date_end(有效期), status(0有效/1用尽/2过期/3已退), note, fk_user_creat, date_creation, tms`
- `llx_clinicpay_card_log`（核销/变动流水，只插不改不删）：`rowid, fk_card, op(CREATE/CHARGE/CONSUME/REFUND/EXPIRE), count_delta, value_delta, fk_bill(可空), fk_user, note, date_creation`
- `llx_clinicpay_bill_sequence`、卡号复用行锁方案（同构 sequence 表）
- 常量：`CLINICPAY_DEFAULT_VAT`（默认 0，诊所免税场景可改）、`CLINICPAY_CARD_GRACE_DAYS`（过期宽限，默认 0）

### 3.2 编号

`SF-YYYYMMDD-NNN`（收费单）、`CK-YYYYMM-NNNN`（卡号，跨月流水）；复制行锁方案。

### 3.3 收费流程

1. **建单**：从患者卡片"收费"Tab 或列表新建；逐行添加收费项目（产品/服务选择器 + 数量；可从发药单带入药品行——pharmacy 就绪后）；金额即时经 `calcul_price_total` 计算并快照。
2. **确认收费**（单事务）：`UPDATE bill SET status=1 WHERE rowid=? AND status=0` 幂等闸门 → 创建原生 Facture（患者 fk_soc、行来自 bill_line、价格快照）→ `Facture::validate()` → 登记收款（channel + ref）→ 发票 rowid 回写 bill → 审计 `CLINICPAY_BILL` → 收费单 PDF（A4，可选）。任一步失败整体回滚（红字：没有发票就没有收费单）。
3. **退费**（两步）：持 `write` 建退费草稿（关联原 bill/发票，自动生成红字发票草稿行）→ 持 `validate` 执行：红字发票 `validate()`（`fk_facture_source`=原票）→ 原通道退回登记 → `bill.status=9` → 审计 `CLINICPAY_REFUND`。原票已作废/已全额红冲 → 拒绝。

### 3.4 次卡/疗程卡

- **售卖/充值**：走收费单（卡对应产品为收费项目），确认后 `CREATE/CHARGE` 流水 + 卡余额/次数增加；同一事务
- **消费核销**（不生成发票）：就诊时从患者卡片选择卡 → `CONSUME`（次数 -1 或储值 -金额）→ 流水；**原子性**：`UPDATE card SET used_count=used_count+1 WHERE rowid=? AND status=0 AND used_count<total_count` 行数 ≠1 → 拒绝（并发防超扣，同行锁方案）
- **退卡**：`REFUND` 清零 + 状态 3；对应退费流程开红票（卡售卖原票）
- **过期**：读取时按 `date_end` 判定展示"已过期"；`EXPIRE` 流水由 Cron 或懒写标记；`CLINICPAY_CARD_GRACE_DAYS` 宽限
- 卡页显示：次数/余额进度、流水时间线；患者卡片"次卡"Tab

### 3.5 状态机与权限

| 动作 | 从 → 到 | 权限 | 条件 |
|---|---|---|---|
| 建收费单/改草稿 | — → 0 / 0 → 0 | `write` | 至少一行；患者有效 |
| 确认收费 | 0 → 1 | `pay` | 通道+流水号（SCAN 必填 ref）；发票创建成功 |
| 建退费草稿 | 1 → 草稿 | `write` | 原票可红冲 |
| 执行退费 | → 9 | `validate` | 与创建不同人或 admin（双人红线）；红票 validate 成功 |
| 卡售卖/充值/退卡 | — | `write` / 退卡需 `validate` | 对应收费单/红票存在 |
| 卡核销 | — | `consume` | 卡有效未过期未用尽 |
| 阅读/列表 | — | `read` | 写 `CLINICPAY_READ` |

权限一级形式：`read / write / pay / validate / consume / admin`。**金额只出自 calcul_price_total 与价格快照；卡核销原子；收费单与卡流水永不删除。**

### 3.6 页面

- 左菜单（`fk_mainmenu=clinic`）：收费列表、次卡列表
- **收费页** `clinicpay/bill.php`：患者横幅 + 明细行编辑器（产品选择器/数量/价格快照/来源处方）+ 通道与流水号 + 确认收费；确认后显示发票链接（跳原生发票卡片）、PDF、退费入口
- **次卡页** `clinicpay/card.php`：卡信息/进度条/流水时间线 + 充值/核销/退卡动作
- **列表** `clinicpay/bill_list.php`、`clinicpay/card_list.php`：编号/卡号/姓名、状态、通道、日期区间
- **患者卡片 Tab**："收费"（该患者收费单 + 新建）与"次卡"（卡列表 + 新卡/核销）
- **设置页**：默认税率、宽限天数、编号预览

### 3.7 PDF

- 收费单 PDF `pdf_sf`（A4）：机构名/"收费单"/编号、患者姓名/卡号、明细（项目/数量/单价/小计）、合计（数字 + GB/T 15835 大写，复用 chinadoc 大写实现方式）、收银员/患者签名位
- 发票本身是原生对象（原生 PDF 模板可用）；收费单 PDF 是前台凭证，两者并存

### 3.8 REST（API 类 `Clinicpay`）

- `GET clinicpay/bills?patient=&status=&from=&to=`（read）
- `GET clinicpay/bills/{id}`（read，写 CLINICPAY_READ；含行）
- `POST clinicpay/bills`（write；行结构同 UI）
- `POST clinicpay/bills/{id}/confirm`（pay；`{channel, channel_ref}`；发票失败 → 409；重复确认幂等）
- `POST clinicpay/bills/{id}/refund`（validate；`{reason, lines?}`）
- `GET clinicpay/cards?patient=&status=`、`GET clinicpay/cards/{id}`（read）
- `POST clinicpay/cards`（write；售卖即建收费单行 → 需再 confirm）
- `POST clinicpay/cards/{id}/consume`（consume；`{count|value, note}`；超扣 → 409）
- 卡退卡走 bill 退费流（不单独端点）

## 4. 明确不做（V0.1）

- 微信支付/支付宝真实对接（需备案域名+商户资质，`PayChannel` 接口位预留，远期）
- 医保结算（非医保纯自费；通道接口一并预留但 V0.1 不实现）
- 套餐组合定价（V0.2：卡产品=多项目打包）；欠费/挂账、找零、发票抬头管理
- 报表/日结（V0.2 候选：收银日报按通道/项目汇总）
- PostgreSQL

## 5. 红线

1. **金额精度**：全部金额经 `calcul_price_total()`/`price2num()`；行小计与总额快照落库，重算仅展示
2. **发票与收费单原子**：确认收费 = 幂等闸门 + 发票创建/validate + 收款登记同事务；失败整体回滚
3. **卡核销原子**：条件 UPDATE 行数闸门防并发超扣；流水只插不改不删
4. **退费双人**：创建（write）与执行（validate）权限分离且不得同人（admin 除外）；红票 `fk_facture_source` 必指原票
5. 收费单/卡/流水**不可物理删除**；全流程审计 `CLINICPAY_BILL/REFUND/CARD_*/READ`
6. 页面、PDF、REST 永不输出患者证件号；金额字段只出现在收费上下文
7. 零 core 修改；API 先 grep；`models` 标量 1；编号与保存同事务
8. 税率默认 `CLINICPAY_DEFAULT_VAT`（诊所免/简化场景 0），改动只影响新单

## 6. 阶段划分

| 阶段 | 交付 | 人工验证 |
|---|---|---|
| 1 骨架 | descriptor（ID 501640、models=1）、4 表 + 2 sequence、常量、权限、菜单、患者 Tab、语言、测试运行器 | UI 启用；"诊所"菜单；患者卡片 Tab |
| 2 收费核心 | `Paybill` 类（create/confirm 原子含发票/退款两步）、`PaybillNumbering` + 单元与并发集成测试、收费页/列表/患者 Tab | 开单→确认→原生发票出现→收款登记；重复确认不重票；退费双人拦截 |
| 3 次卡 | `ServiceCard` 类（售卖挂 bill/原子核销/退卡/过期）、`CardNumbering`、卡页/流水/患者 Tab、卡核销并发测试 | 售卡→发票；核销防超扣（并发 20）；退卡红票 |
| 4 集成面 | `pdf_sf`、REST 8 端点、停用→启用全流程、`v0.1.0` | PDF 人工核对；REST 权限矩阵（403/409）；停用→启用无损 |

## 7. 验收标准

- A. 干净库启用 → 开单 → 确认 → 原生发票与收款可见 → 停用 → 启用，数据无损
- B. 并发 20 确认同一草稿：只生成一张发票；并发 20 核销最后 1 次：恰一次成功
- C. 金额：含税/免税、多行、数量小数经 calcul_price_total 与发票金额一致（发票对账）
- D. 退费：红票金额=原票对应行；原票不可重复退；双人红线（同人非 admin 拒绝）
- E. 卡：售卖→发票；核销流水完整；过期+宽限判定正确；退卡清零+红票
- F. 权限：无 `pay` 不能确认；`validate` 与 `write` 分离；REST 403 矩阵
- G. 审计六类动作可见；页面/PDF/REST 无证件号
- H. `tests/run_all.php` 全过；README/descriptor 版本一致；AGENTS.md 更新

## 8. 开放项（2026-09-22 评审已定）

1. **收费项目初始化**：种子 SQL（挂号/诊金等 3-5 个常设项目，code 唯一可重复导入）
2. **默认税率**：`CLINICPAY_DEFAULT_VAT=0`（诊所免/简化口径），设置页可改，只影响新单
3. **药品行来源**：V0.1 手工添加（与 prescription spec §0 决议一致）；pharmacy 0.1.0 发布后加"从发药单带入"
4. **收银日报**：V0.1 只做列表过滤（按日期/通道）；正式日报 V0.2

## 9. 模块标识

- 目录 `htdocs/custom/clinicpay/`，类 `modClinicPay`，常量 `MAIN_MODULE_CLINICPAY`
- 模块 ID **501640**；权限 ID `50164011/21/31/41/51/61`（read/write/pay/validate/consume/admin）
- `depends = array('modPatient', 'modFacture', 'modBanque')`（core 模块名已核对：`modFacture.class.php:38`、`modBanque.class.php:36`）；语言 `langs/zh_CN/clinicpay.lang`、`langs/en_US/clinicpay.lang`
- 仓库 `github.com/kongzong/dolibarr-modclinicpay`（推送前由维护者创建）
