# Telegram: from creating a bot to your first backup

TG Backup is a free, open-source plugin. You need a Telegram account and own the bot you create. You do not need a TG Backup subscription, a third-party backup service, or Telegram Premium. Your hosting, Local Bot API server, or proxy provider may charge for their services.

## 1. Create a dedicated bot

1. Open the [official @BotFather](https://t.me/BotFather) in Telegram. Check that the username is exactly `BotFather`.
2. Send `/newbot`. Choose a descriptive name, such as “My website backups,” followed by an available username ending in `bot`.
3. Copy the Bot Token into **Settings → TG Backup → Connection**. The token grants control of your bot. Do not send it to support, publish screenshots of the token field, or paste it into a browser address bar.
4. Open your new bot and press **Start**. A bot cannot initiate a private conversation with a user.

Jobs are managed in WordPress. In Telegram, the bot delivers separate silent document messages and attempts one status message per job, updated in place. Messages or commands sent to the bot do not trigger website restoration, deletion, or backup jobs.

A dedicated backup bot avoids conflicts with another service that already consumes the bot's incoming messages. If the token is exposed, revoke it through BotFather and save the replacement in the plugin.

## 2. Choose a destination

For a private chat, press Start, then use **Find chat from a message** and select yourself. The plugin inspects the first 100 pending updates without acknowledging or deleting them. If your chat is missing, send the bot a new message. Chats whose updates have already been consumed by another application may not appear; you can enter the Chat ID manually.

For a group, add the bot and send `/start@your_bot_username`. The bot must have permission to send messages and documents. For a group topic, send the command inside that topic, then select the discovered topic or enter its positive Thread ID separately. Group IDs often begin with `-100`; keep the minus sign.

For a channel, add the bot as an administrator with permission to publish messages. Use `@username` for a public channel or its numeric Chat ID for a private channel. Publish a new channel post so that it can appear in chat discovery. Run a delivery test before the first backup.

If another application has configured a webhook for the bot, backup uploads can still work, but automatic chat discovery is disabled. The plugin does not change or remove the webhook. Enter the Chat ID manually or use a dedicated bot.

## 3. Test the connection

Save the token, Chat ID, and network settings. Confirm consent to send data to the selected Bot API. Then run the checks:

- **Network** — the server supports the selected cURL, DNS, proxy, and TLS settings.
- **Bot** — the Bot API accepted the token and returned the bot account.
- **Compatibility** — the presence of a webhook was checked without exposing its address.
- **Chat** — the bot can see the destination. This does not yet prove permission to send documents or access the selected topic.
- **Test document** — only the separate send action tests an actual upload, permissions, and topic. The file contains no website data. The local test file is removed after the attempt.

The ordinary connection check sends no messages to the chat. Use **Send test file** to test delivery, and confirm that the document arrives in the intended destination. A small test validates the route; it does not establish the available bandwidth for a backup of several gigabytes.

## 4. File size: two API modes

| Mode | Official upload limit | TG Backup part limit | Requirements |
| --- | --- | --- | --- |
| Telegram Cloud Bot API | 50 MB | 45 MiB; 40 MiB by default | Bot token and access to `api.telegram.org` |
| Your own Local Bot API Server | 2000 MB | 1900 MiB | A separately installed Bot API server running in `--local` mode |

1900 MiB equals 1,992,294,400 bytes, leaving a small margin below the official 2000 MB limit. The ordinary cloud API does not accept a 2 GB document. Large backups are split automatically, and an individual source file can also exceed the part size. You need every part to restore a backup. See [Telegram's official local server limits](https://core.telegram.org/bots/api#using-a-local-bot-api-server) and the [cloud sendDocument limit](https://core.telegram.org/bots/api#senddocument).

**Telegram Premium does not increase bot upload limits.** Its [4 GB upload allowance](https://telegram.org/faq_premium) applies to uploads from your personal account in Telegram apps. This plugin sends through the Bot API, whose limits remain 50 MB in cloud mode or 2000 MB through your own Local Bot API server. Buying Premium for the bot owner or recipient does not change these limits.

MiB and GiB in the settings are binary units: 1 MiB = 1,048,576 bytes. The maximum backup size, part size, and local storage quota are separate limits. For example: a 5120 MiB backup maximum, 40 MiB parts, and a 10240 MiB local quota. If physical space or quota is insufficient, the job stops with an explanation. Existing unsent parts are not deleted to make room for a new backup.

## 5. Your own server for parts close to 2 GB

This is separate infrastructure; the plugin does not install it automatically. Do not enter an untrusted “public Bot API” address: that server would receive your bot token and encrypted archives.

1. Deploy the [official telegram-bot-api server](https://github.com/tdlib/telegram-bot-api) using the project's instructions. Obtain a Telegram API ID and API hash for the server through [my.telegram.org](https://my.telegram.org). These are separate from the Bot Token and are not required by the plugin.
2. Run the server with `--local`, a persistent working directory, a dedicated operating-system account, and enough disk space for its temporary files. Do not expose an unprotected HTTP port to the internet.
3. If the bot is already using the Cloud Bot API, perform Telegram's documented migration step with `logOut` on the cloud server before switching to local. This affects other applications using the bot. The plugin does not perform it automatically; the server administrator follows [Telegram's migration instructions](https://core.telegram.org/bots/features#local-bot-api).
4. Select Local in the plugin and enter the base URL, such as `http://127.0.0.1:8081` when the server runs on the same host, or your own HTTPS address. Do not append `/botTOKEN` or a method name. HTTP is allowed only for localhost or a private IP. HTTP on a local network does not encrypt the token in the request path: use a trusted network or HTTPS.
5. Increase the permitted request size and timeout on your reverse proxy. Allow enough PHP/worker execution time to upload an entire part. Raising `upload_max_filesize` on WordPress itself does not increase the outgoing upload limit; the receiving Bot API/proxy limits and the lifetime of the sending process are what matter.
6. Start with a small part, verify actual delivery and restoration, and then increase the part size as needed, up to 1900 MiB.

At an outgoing speed of 10 Mbit/s, a 1900 MiB part takes about 27 minutes to upload, excluding overhead. The transport allows up to 60 minutes per local upload and 10 minutes per cloud upload. PHP-FPM, web server, or hosting limits may terminate the request sooner. Choose smaller parts on ordinary shared hosting. For large parts, use a reliable cron/CLI worker as described in the main plugin instructions.

## What is sent to Telegram

Archive parts contain encrypted site data. Job captions and the status message, `manifest.json`, and the recovery script are sent unencrypted. They include job IDs, dates, backup type, counts, part filenames, sizes, hashes and error summaries. The archive password is never sent. Telegram bot conversations are not end-to-end encrypted; archive payload protection comes from encryption on your WordPress server before upload.

## One status message, silent documents

When work starts, the plugin attempts to send one status message for the job. It edits the same message with the stage, estimated overall progress, archive size and number of parts delivered. Normal progress edits are spaced at least 30 seconds apart; completion, retry, failure and cancellation also attempt an update. This is not a live byte counter or an upload-time estimate.

Archive parts, `manifest.json` and `restore.php` are separate document messages with silent delivery requested (`disable_notification=true`). They remain visible in the chat. Telegram defines silent delivery as a notification without sound; clients may still show silent notifications. To stop per-file notifications, mute the destination chat in Telegram. The initial status message follows the same chat notification settings.

For public channels/supergroups, links use the public username. Private supergroup/channel links require access to that chat. A status message includes at most 10 part links plus the manifest and recovery tool; find additional documents using the job ID. **Private one-to-one bot chats and basic groups do not have supported native message permalinks.** The plugin does not publish archives to obtain public links.

Status reporting is best effort. If its initial response is lost, the plugin does not send a replacement announcement, avoiding duplicate announcements. If an edit fails, backup processing continues, and the failure is logged in WordPress. A deleted or inaccessible status message may remain unavailable. Use the WordPress job state and the actual downloaded archive to verify the result.

## What “delivered” means

Delivery requires Telegram confirmation for every part, the manifest and the recovery tool. The optional status message is not part of that delivery condition. Validating the token or starting the queue does not mean the backup has been delivered. After a network interruption, Telegram may have accepted a document even if WordPress did not receive the response. A retry can create a duplicate; matching part filenames and job IDs help identify it.

Local deletion and retention rules apply to the WordPress server. Documents in Telegram are not deleted automatically. The plugin does not measure free space in a Telegram account, on the recipient's devices, or on a separate Local Bot API server.

Keep the archive password separate from the website and Telegram. Download all parts and the restore utility. Restore at least one backup into a separate empty directory, then check its files and SQL. The bot cannot recover a lost archive password.
