# Learning Assist Block for Moodle

AI Learning Assistant for students

This block provides AI-powered assistance and content summarization for supported Moodle modules. It includes support for converting HTML content to Markdown.

---

## 📦 Requirements

This plugin uses the [`league/html-to-markdown`](https://github.com/thephpleague/html-to-markdown) package.

To ensure compatibility across installations, this dependency is bundled using Composer and stored locally within the plugin.

---

## 🔧 How to Install Dependencies

1. **Navigate to the plugin directory**

   ```bash
   cd /path/to/moodle/blocks/learningassist
   ```

2. **Download Composer locally (optional)**

   If Composer is not installed globally:

   ```bash
   curl -sS https://getcomposer.org/installer | php
   ```

3. **Install the dependency**

   If you have global Composer:

   ```bash
   composer require league/html-to-markdown
   ```

   Or using the local Composer binary:

   ```bash
   php composer.phar require league/html-to-markdown
   ```

   This will create a `vendor/` folder with the required autoload and library files.

---

## ✅ Make Sure to Load the Autoloader

In any PHP file where the library is needed, include:

```php
require_once(__DIR__ . '/../vendor/autoload.php');
// or, if in the plugin root:
require_once(__DIR__ . '/vendor/autoload.php');
```

---

## 📁 Include `vendor/` in Your Plugin Distribution

When packaging this plugin (e.g., for deployment or sharing), include the `vendor/` folder in your Git repo or ZIP file. This avoids requiring Composer on the target system.

---

## 📝 Notes

- Do **not** add this plugin’s `composer.json` to Moodle’s root `composer.json`.
- This approach keeps the plugin self-contained and avoids conflicts with Moodle core or other plugins.