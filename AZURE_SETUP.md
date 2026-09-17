# Microsoft OAuth Setup Guide

This guide walks you through registering an app in Azure / Microsoft Entra ID so staff can sign in to Impact Score using their Microsoft work accounts.

---

## Prerequisites

- Admin access to your organization's Microsoft 365 / Azure tenant
- The production URL where Impact Score is hosted (e.g., `https://impactscore.yourlibrary.org`)

---

## Step 1 — Register the Application

1. Go to the [Azure Portal](https://portal.azure.com) and sign in with an admin account.
2. Search for **Microsoft Entra ID** (formerly Azure Active Directory) in the top search bar.
3. In the left sidebar, click **App registrations**.
4. Click **+ New registration**.
5. Fill in the form:
   - **Name:** `Impact Score` (or anything descriptive)
   - **Supported account types:** Select **"Accounts in this organizational directory only"** (single tenant — recommended for library staff)
   - **Redirect URI:** Select **Web** and enter:
     ```
     https://yourdomain.com/oauth/microsoft_callback.php
     ```
     Replace `yourdomain.com` with your actual domain.
6. Click **Register**.

---

## Step 2 — Copy the Application IDs

On the app's Overview page you will see:

| Field | Where to find it |
|---|---|
| Application (client) ID | Overview page, copy it |
| Directory (tenant) ID | Overview page, copy it |

Keep these handy — you'll paste them into the config file in Step 4.

---

## Step 3 — Create a Client Secret

1. In the left sidebar, click **Certificates & secrets**.
2. Click **+ New client secret**.
3. Add a description (e.g., `Impact Score production`) and choose an expiry (24 months recommended).
4. Click **Add**.
5. **Copy the Value immediately** — you cannot see it again after leaving this page.

> ⚠️ Copy the **Value** column, not the **Secret ID** column.

---

## Step 4 — Update the Config File

Edit `secure/microsoft_oauth.php`:

```php
return [
    'client_id'     => 'paste-your-application-client-id-here',
    'client_secret' => 'paste-your-client-secret-value-here',
    'tenant_id'     => 'paste-your-directory-tenant-id-here',
    'redirect_uri'  => 'https://yourdomain.com/oauth/microsoft_callback.php',
];
```

This file is outside version control and should never be committed.

---

## Step 5 — Verify API Permissions

1. In the left sidebar, click **API permissions**.
2. You should already see **Microsoft Graph → openid, profile, email** delegated permissions. If not:
   - Click **+ Add a permission → Microsoft Graph → Delegated permissions**
   - Search for and add: `openid`, `profile`, `email`
3. Click **Grant admin consent** (the blue button) and confirm.

---

## Step 6 — Test

1. Go to your Impact Score login page.
2. The "Sign in with Microsoft" button should now be active (not greyed out).
3. Click it — you should be redirected to Microsoft's login page.
4. Sign in with a staff Microsoft account.
5. If the email matches a user in the system, you'll be logged in and the Microsoft OID will be linked to that account automatically.

---

## Troubleshooting

**"No account is linked to that Microsoft login"**
The email from Microsoft doesn't match any user record. Go to Admin → Manage Users and set the Microsoft email for that user, or make sure the email in their user record matches their Microsoft work email.

**"Microsoft sign-in failed"**
Check `error_log.txt` in the app root for `[OAuth]` log entries with the specific error message from Microsoft.

**"AADSTS50011: The redirect URI specified in the request does not match"**
The redirect URI in `secure/microsoft_oauth.php` must exactly match what you entered in Azure (including http vs https, trailing slashes, etc.).

**Client secret expired**
Secrets expire. When they do, go back to Azure → Certificates & secrets, create a new secret, and update `secure/microsoft_oauth.php`.

---

## Renewing the Client Secret

Secrets expire (you set this in Step 3). When yours expires:

1. Azure Portal → Microsoft Entra ID → App registrations → your app → Certificates & secrets
2. Create a new secret
3. Update `secure/microsoft_oauth.php` with the new value
4. The old secret can be deleted after confirming sign-in works

Set a calendar reminder 30 days before expiry.
