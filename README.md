# Magento 2 Admin Password Command Module

## Overview

This Magento 2 module provides a simple and robust command-line interface (CLI) command to forcefully set the password for any admin user.

It is built following modern Magento 2 and PHP best practices, including strict typing, fail-fast validation, and clean dependency injection. It serves as a reliable alternative to older or core commands that may have compatibility issues.

## Features

- **Robust Validation:** Sequentially validates username and password to provide clear, single error messages.
- **Interactive Mode:** Run the command without arguments to be prompted for the username and password securely.
- **Non-Interactive Mode:** Provide the username and password as options for use in automated scripts.
- **PSR-12 Compliant:** The code adheres to the highest coding standards.
- **User-Friendly:** Prevents fatal errors and stack traces on invalid user input.

---

## Compatibility

- **Adobe Commerce / Magento Open Source:** ~2.4.4
- **PHP:** ~8.1 || ~8.2 || ~8.3 || ~8.4

This module has been successfully tested on **Adobe Commerce version 2.4.7-p5**.

---

## Installation

This module should be installed via Composer.

1.  **Require the package:**
    ```bash
    composer require iop/module-admin-command
    ```

2.  **Enable the module and run setup:**
    ```bash
    bin/magento module:enable Iop_Admin
    bin/magento setup:upgrade
    bin/magento setup:di:compile
    bin/magento cache:clean
    ```

---

## Usage

The module adds the `iop:admin:user:set-password` command.

### Interactive Usage

For the most straightforward use case, run the command without any options. The command will prompt you to enter the username and password.

```bash
bin/magento iop:admin:user:set-password
