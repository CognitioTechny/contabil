# Project Specifications: Contábil Module

## 1. Overview
**Contábil** is a backend-only accounting module designed for CodeIgniter 4 applications. It provides a robust, double-entry bookkeeping engine that can be seamlessly integrated into existing CI4 projects. The module is strictly agnostic, focusing on core accounting mechanics without being tied to specific regional tax laws or business interfaces.

## 2. Core Features
The module handles standard accounting routines and provides native reporting capabilities:
* **Chart of Accounts (CoA):** Hierarchical management of accounting accounts.
* **Journal Entries:** Transaction recording using the double-entry bookkeeping system (debits and credits).
* **General Ledger:** Complete record of all financial transactions mapped to specific accounts.
* **Trial Balances & Native Reporting:** Built-in services to generate trial balances and essential financial reports natively.

## 3. Architecture & Design
* **Backend-Only (Headless):** The module strictly provides backend infrastructure (Models, Entities, Services, and optionally API Controllers). It does not inject Views, UI components, or frontend assets.
* **Agnostic Rules:** Built around universal accounting principles. It does not enforce specific national accounting standards, allowing developers to adapt it to their regional needs.
* **Database Agnostic:** Fully relies on CodeIgniter 4's built-in `Forge` and `Migration` classes, ensuring compatibility with any database supported by the framework (MySQL, PostgreSQL, etc.).

## 4. Technical Requirements
To install and run this module, the host application must meet the following minimum requirements:
* **PHP:** 8.2 or higher
* **CodeIgniter Framework:** 4.7 or higher
* **Package Manager:** Composer (for module installation and autoloading)

## 5. Out of Scope
To maintain a clear and lightweight architecture, the following features are explicitly **out of scope** for this module:
* **Tax Management (Fiscal):** Calculation, tracking, or generation of tax-related data and obligations.
* **Payroll & HR (Pessoal):** Employee management, wage calculations, or labor-related accounting.
* **Frontend UI:** Pre-built dashboards, forms, or views.

## 6. License
This project is open-source and distributed under the **MIT License**.
