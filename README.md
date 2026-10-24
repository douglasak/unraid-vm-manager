# vm.manager — Unraid VM management plugin

A small PHP/web interface to manage libvirt VMs on Unraid: start, stop, restart,
force-stop, force-restart, set RAM, and toggle GPU passthrough — from an
auto-refreshing table that mirrors Unraid's native styling.

Release: **2026.10.24** · License: **MIT** · Repo: **github.com/douglasak/vm-manager**

> **Use at your own risk.** This plugin controls virtual machines directly via
> `virsh` — including force-stop, reset, RAM changes, and attaching/detaching
> GPUs. Actions take effect immediately and can interrupt running VMs or lead to
> data loss. It is provided **AS IS, without warranty of any kind** (see
> [LICENSE](LICENSE)). You are solely responsible for what you do with it.

## How it works

The plugin runs **on** the Unraid server. Unraid's webGUI (emhttp) and its
php-fpm worker run as **root on the same host as libvirt**, so the plugin calls
`virsh` directly — there is no SSH, no agent, and nothing to stage on the host.
The core logic lives in `include/vmlib.php` (thin `virsh` wrappers plus the
action handlers); `include/api.php` is a small JSON endpoint the page calls; and
`vm.manager.page` is the GUI.

## Install

**From URL (recommended):** Unraid GUI → **Plugins → Install Plugin**, paste:

```
https://raw.githubusercontent.com/douglasak/vm-manager/main/vm.manager.plg
```

and click Install. Installing this way registers the `pluginURL`, so Unraid will
notify you of updates when a newer version is pushed to the repo.

**From a local file:** copy `vm.manager.plg` to the server (e.g. to
`/boot/config/plugins/`) and install that path instead.

Then open it at **Settings → Utilities → VM Manager**.

The `.plg` is self-contained — every file is inlined, so there is no external
download. Unraid re-runs the `.plg` on each boot, which recreates the plugin's
files in the RAM-backed `/usr/local/emhttp` automatically. Your GPU configuration
is stored separately on the flash share (`/boot/config/plugins/vm.manager/`) and
persists across reboots and reinstalls.

To uninstall: **Plugins → vm.manager → Remove** (wipes the plugin directory; the
saved GPU config on the flash share is left in place).

## Files it installs

```
/usr/local/emhttp/plugins/vm.manager/
  vm.manager.page      GUI page (Settings → Utilities)
  include/vmlib.php     virsh wrappers + action handlers + GPU config
  include/api.php       JSON endpoint (list, token, memstatus, gpucfg, actions)
  LICENSE               MIT license
```

## Using it

The table lists every VM with its state, configured RAM, and GPU-passthrough
status, and refreshes every 5 seconds.

- **State colours:** green = running, grey = shut off, amber = paused / in
  shutdown. Per-row buttons enable or disable themselves by state (you can't
  Start a running VM, etc.).
- **Start / Stop / Restart:** graceful operations (`virsh start` / `shutdown` /
  `reboot`).
- **Force stop / Force restart:** hard `virsh destroy` / `reset`, each behind a
  confirmation prompt.
- **RAM:** type a size (`8G`, `8192M`, or a bare number = MB) and click the
  check. This sets both max and current memory with `--config`. Because libvirt
  only allows the max to change while a VM is off, changing RAM on a **running**
  VM triggers a stop → apply → start cycle. That runs in the background so the
  page doesn't hang; the status line then reports whether the VM came back up,
  and explicitly reports an error if the restart fails (e.g. the host can't
  satisfy the new allocation). Sizes are bounded to 256 MiB – 512 GiB.
- **GPU:** the toggle is enabled only when the VM is **shut off** and a GPU has
  been configured (see below). Toggling attaches or detaches the GPU's VGA (and
  audio) hostdevs with `--config`. It does **not** touch the VNC `<graphics>`
  block, so VNC remains defined; start the VM afterward to use the new display
  path.

## Versioning VM XML in git (optional)

If `/etc/libvirt/qemu` is a git repository, every RAM or GPU change made from
the plugin commits that VM's `<name>.xml` (only that file) and pushes it to the
repo's upstream. This runs in the background, so the page never waits on the
network.

- **Author:** the repo's `user.name` / `user.email` if set, otherwise the
  author of the latest commit.
- **Auth:** pushes run as root with `HOME=/root`, so root's SSH key
  (`/root/.ssh`) must be authorised on the remote. Pushes are non-interactive
  and give up after a timeout.
- **Failures** (commit errors, rejected or timed-out pushes) are logged to
  syslog under the tag `vm.manager`. A rejected push leaves the commit in place
  locally; pull and push manually to reconcile.
- **Turn off** by setting `"git_commit": false` (no commits) or
  `"git_push": false` (commit locally only) in
  `/boot/config/plugins/vm.manager/vm.manager.cfg`.

## GPU passthrough setup

GPU passthrough is configured at runtime — nothing is hardcoded. Expand
**GPU passthrough setup** at the bottom of the page:

1. The **GPU** dropdown lists this server's display devices (auto-detected via
   `lspci`). Pick the card you pass through; the plugin uses its VGA function
   (`.0`) and, if present, its audio function (`.1`).
2. **Guest bus / slot** are optional. Leave both blank to let libvirt assign the
   guest PCI address automatically (simplest, works for most setups). Set them
   (e.g. bus `0x06`, slot `0x00`) to pin the card to a fixed guest address as a
   multifunction device — useful if a guest OS is picky about the GPU's address.
3. Click **Save GPU config**. The selection is written to the flash share and
   persists.

Detection of whether a VM currently has the GPU is based on the card's **host
source** PCI address appearing in the VM's inactive XML, so it won't false-match
on guest-side addresses.

Notes:
- Built around a **single** passthrough GPU with an optional audio function.
  Cards exposing extra functions (USB-C, etc.) aren't handled automatically.
- If `lspci` lists no display devices (or the GPU is already bound elsewhere),
  the dropdown may be empty; the GPU toggle stays disabled until a card is saved.

## Security

- The page lives under emhttp, so it sits behind Unraid's normal login.
- Every state-changing request requires a valid Unraid **CSRF token**
  (`hash_equals` against `/var/local/emhttp/var.ini`). The page fetches the live
  token from the plugin's own `?action=token` endpoint via **jQuery GET** right
  before each action, then includes it in the request.
- Why this path: on servers using Strict SSL / Unraid Connect remote access, the
  `myunraid.net` proxy redirects requests that don't look like API calls. jQuery
  sends `X-Requested-With: XMLHttpRequest`, which the proxy honours (it's the same
  path the GUI's own table refresh uses). Native `fetch`, the inline-embedded
  token, and the global browser `csrf_token` all proved unreliable through that
  proxy; fetching the token from the server over the jQuery path is what works.
- Actions are sent as authenticated **GET** requests carrying the CSRF token —
  behind Unraid's login and not cached (`cache:false`). They can be switched to
  POST if preferred.
- Every VM name is validated against the live `virsh list --all --name`, and the
  GPU selection against the live `lspci` scan, before anything acts on them. All
  arguments are passed through `escapeshellarg`.

## Building from source

The `.plg` is generated from the files under `src/`:

- **Edit logic:** change files in `src/`, then run `python3 build_plg.py` to
  regenerate the self-contained `.plg` (it inlines each file as a CDATA block).
- **Bump the version:** edit `VER` in `build_plg.py` — Unraid keys updates off
  the version string.
- **Move the menu:** change `Menu="Utilities"` at the top of `vm.manager.page`
  (e.g. to `Tools`) and the `launch=` attribute in `build_plg.py`.

## Limitations

- Assumes libvirt is enabled (VMs turned on in Unraid) and `virsh` is present.
- Single passthrough GPU (+ optional audio function); multi-GPU or
  multi-function cards need extra handling.
- Developed and tested on a single server and Unraid configuration; other
  environments may surface different assumptions. Bug reports and fixes welcome.

## Repository & updates

Canonical home: <https://github.com/douglasak/vm-manager>. The built
`vm.manager.plg` lives at the repo root, and its `pluginURL` points at the raw
file on the `main` branch, so Unraid checks there for updates. To publish a new
version: edit `src/`, bump `VER` in `build_plg.py`, run `python3 build_plg.py`,
and commit the regenerated `vm.manager.plg`. Unraid compares the version string
and offers the update to anyone who installed from the URL.

> If your repo's default branch is `master` rather than `main`, change `BRANCH`
> in `build_plg.py` and reinstall, so the update URL resolves.

Suggested repo layout:

```
vm-manager/
  vm.manager.plg      built plugin (raw URL target; commit after each build)
  build_plg.py        builder
  README.md
  LICENSE
  src/
    vm.manager.page
    include/vmlib.php
    include/api.php
    LICENSE
```

## Publishing to Community Applications (optional)

The plugin is self-contained and updatable on its own via the `pluginURL`. To
also list it in **Community Applications**:

1. Push this repo to GitHub (public).
2. Optionally start an Unraid support thread and set `support` (in
   `build_plg.py`) to it; it currently points at the repo's Issues page.
3. Submit the plugin to the Community Applications feed following their current
   template and moderation guidelines (see the CA documentation / the
   `community.applications` support thread on the Unraid forums).
4. CA shows the `icon` (currently the `desktop` FontAwesome glyph). To use a
   custom image instead, commit an `icon.png` to the repo root and point the
   `icon` attribute in `build_plg.py` at its raw URL.

## License

MIT — see [LICENSE](LICENSE). Provided AS IS, without warranty; use at your own
risk.
