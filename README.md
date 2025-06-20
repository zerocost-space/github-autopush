# GitHub Autopush WordPress Plugin

**GitHub Autopush** is a lightweight WordPress plugin that automatically commits and pushes the contents of a specified directory to a GitHub repository when a specific WordPress action hook is triggered **with a matching parameter value**.

## 🔧 Features

- **Custom Action Hook Trigger**: Define any WordPress action hook to trigger the git push.
- **Required Parameter Matching**: The hook only triggers if the specified parameter has the configured value.
- **Directory Configuration**: Set which directory’s contents to push.
- **GitHub Repository Integration**: Push to any GitHub repository using token-based authentication.
- **Workflow Friendly**: Easily integrates into static site generation or other automation pipelines.

## 🚀 Use Case

A popular scenario is using this plugin with the free version of Simply Static, enabling automatic deployment of generated static files to Cloudflare Pages or GitHub Pages.

One real-world example is covered in this tutorial:

👉 [Deploy your WordPress site to Cloudflare Pages – A step-by-step guide](https://zerocost.space/tutorial/deploy-your-wordpress-site-to-cloudflare-pages-a-step-by-step-guide/)

## ⚙️ Plugin Configuration

Configure the plugin settings via the WordPress admin panel:

| Setting | Description | Example |
|--------|-------------|---------|
| **GitHub Personal Access Token** | Token used to authenticate with GitHub. | `Your api key` |
| **GitHub Repository** | Repository to push to, in the format `username/repository`. | `zerocost-space/demosite` |
| **Source Folder** | Absolute path to the folder whose contents will be pushed. | `/storage/Data/www/html/demosite/public_static` |
| **Trigger Action Hook** | The WordPress action hook that triggers the push. | `ss_completed` |
| **Trigger Action Parameter** | Name and expected value of the parameter that must match for the action to execute. | e.g. `success` |

> ℹ️ The action will **only execute** if the specified parameter has the exact expected value when the hook is fired.

## 🛡️ Requirements

- WordPress 5.0 or higher  
- PHP 7.4 or higher  
- GitHub personal access token

## 📝 License

This plugin is open source and licensed under the GPLv2 License.

---

**Automate your WordPress deployment with GitHub and custom hooks.**
# github-autopush

