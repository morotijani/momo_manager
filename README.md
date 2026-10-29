# MoMo Manager

Multi-Owner, Multi-Branch Mobile Money Merchant & Vendor Management Web App built for Ghana (**MTN Mobile Money**, **Telecel Cash**, and **AirtelTigo Cash / AT Money**).

Designed with a centered **iPhone 16 (`393px × 852px`)** mobile-first viewport so it feels like a native fintech mobile app whether opened on a kiosk smartphone or on the Enterprise Owner's laptop.

## Core Capabilities

- **Multi-Owner SaaS Ready:** Any individual business owner can register their own enterprise (e.g., *Move Money Enterprise*), create unlimited branches, configure Merchant SIM wallets, and employ branch vendors.
- **Two-Bucket Live Ledger (`Physical Cash` vs. `SIM E-Cash`):**
  - **Deposit:** `+Amount` Drawer Cash, `-Amount` Network E-Cash.
  - **Transfer:** `+(Amount + OTC Fee)` Drawer Cash, `-Amount` Network E-Cash (supports cross-network interoperability and sender details).
  - **Withdrawal:** Enforces *"Allow Cash Out" + Customer PIN* checklist; `-Amount` Drawer Cash, `+Amount` Network E-Cash.
- **1-Tap Telco SMS Parser:** Paste confirmation SMS messages from MTN MoMo, Telecel Cash, or AirtelTigo Cash to auto-extract network, transaction type, customer phone/name, amount, Transaction ID, and reference.
- **Immutable Audit Ledger:** Unique `(company_id, network, telco_tx_id)` duplicate protection. Vendors cannot delete or silently edit records—mistakes go through a **Void / Correction Request** queue that reverses balances atomically only upon Boss approval.
- **Daily Shift Reconciliation:** Snapshots opening cash/float and flags closing shortages or overages automatically.

## Running on XAMPP

1. Start **Apache** and **MySQL** in the **XAMPP Control Panel**.
2. Open **`http://localhost/momo_manager/`** in your browser.
3. On first visit, MoMo Manager automatically creates the `momo_manager` MySQL database, runs [`database/schema.sql`](database/schema.sql), and seeds a demo **Move Money Enterprise** workspace.

### Pre-Seeded Demo Logins (`Password@123`)

| Role | Name | Phone | Assigned Branch |
| :--- | :--- | :--- | :--- |
| **Main Boss (`OWNER`)** | Nana Kwaku Mensah | `0244000001` | All 3 Branches |
| **Vendor (`VENDOR`)** | Ama Boateng | `0244000002` | Madina Zongo Junction |
| **Vendor (`VENDOR`)** | Kwame Asante | `0544000003` | Circle Kwame Nkrumah |
| **Vendor (`VENDOR`)** | Efua Owusu | `0204000004` | Kasoa New Market |

## Running Automated Tests

```powershell
c:\xampp\php\php.exe tests/integration_test.php
```
