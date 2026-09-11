# User guide

Install: see the [README](../README.md).

## Administration

Open **Administration → Share Target**.

**Who can set a share target.** Either all accounts, or only the accounts
and groups you pick. The default is the second mode with nobody selected,
so the field appears for nobody until you add an account or a group.

**Parent folder.** Two switches, both off, both for account shares only.

- *Make the parent folder if it does not exist.* A target `Work/Client
  Files` needs `Work` in the account of the recipient. With the switch off,
  the share is refused when `Work` is missing.
- *Remove that folder again when the share goes and the folder is empty.*
  The app removes only a folder it made, and only while the folder holds
  nothing.

## Use

1. Open the **Sharing** tab of the sidebar for the file or folder.
2. Add the recipient, an account or a group.
3. On the new share, open **Advanced settings**.
4. Fill in **Path in the recipient's account**. The line under the field
   shows the result while you type. Leave it empty to share the normal way.

The last part of the path is the name of the share. The target applies
once, at creation. To change it, unshare and share again. A target that you
set and never use is forgotten after one hour.

## Group shares

A group share holds one target, and every member sees the share at that
path. The app makes no folder in the home of a member. When the target has
a parent folder and the home of a member does not hold it, Nextcloud puts
the share in the share folder of that member instead, under its normal
name.

## Troubleshooting

- **The field does not show.** The account may not set a target. Check
  **Administration → Share Target**. The field also stays hidden for link
  shares, federated shares, and shares that already exist.
- **The share was refused: the folder is not in the account of the
  recipient.** Turn on *Make the parent folder if it does not exist*, or
  ask the recipient to make the folder first.
- **A group member got the share in the share folder.** The home of that
  member does not hold the parent folder of the target. See *Group shares*.
