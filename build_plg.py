#!/usr/bin/env python3
"""Assemble vm.manager.plg by inlining the source tree as CDATA <FILE> blocks."""
import os

HERE  = os.path.dirname(os.path.abspath(__file__))
SRC   = os.path.join(HERE, "src")
OUT   = os.path.join(HERE, "vm.manager.plg")
AUTH   = "douglasak"
VER    = "2026.10.24"
GH_USER = "douglasak"
GH_REPO = "vm-manager"
BRANCH  = "main"   # change to "master" if your repo's default branch is master
PLUGIN_URL = f"https://raw.githubusercontent.com/{GH_USER}/{GH_REPO}/{BRANCH}/vm.manager.plg"
SUPPORT    = f"https://github.com/{GH_USER}/{GH_REPO}/issues"

# (source path relative to SRC, destination path on Unraid, mode)
FILES = [
    ("vm.manager.page",   "&plugdir;/vm.manager.page",   "0644"),
    ("include/vmlib.php", "&plugdir;/include/vmlib.php", "0644"),
    ("include/api.php",   "&plugdir;/include/api.php",   "0644"),
    ("LICENSE",           "&plugdir;/LICENSE",           "0644"),
]

def cdata(text: str) -> str:
    # CDATA can't contain the literal ]]> — none of our files do, but guard anyway.
    return text.replace("]]>", "]]]]><![CDATA[>")

parts = []
parts.append(f"""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "vm.manager">
<!ENTITY author    "{AUTH}">
<!ENTITY version   "{VER}">
<!ENTITY pluginURL "{PLUGIN_URL}">
<!ENTITY support   "{SUPPORT}">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        support="&support;"
        launch="Settings/VMManager"
        icon="desktop"
        min="6.10.0">

<CHANGES>
###&version;
- If /etc/libvirt/qemu is a git repo, RAM and GPU changes commit the VM's
  XML and push it to the repo's upstream (in the background; errors go to
  syslog). Disable with "git_commit": false / "git_push": false in the cfg.

###2026.06.08
- Add pluginURL (GitHub) for update checks, support URL, and CA metadata.

###2026.06.07
- On-host VM manager: start / stop / restart / force-restart / force-stop,
  set RAM, and toggle GPU passthrough, in an auto-refreshing table.
- GPU passthrough is configured at runtime (auto-detected via lspci) rather
  than hardcoded, so it works on any host.
- Running-VM RAM changes report success/failure of the restart.
- MIT licensed. Provided AS IS, without warranty; use at your own risk.
</CHANGES>

<!-- clean any prior install, recreate the tree (runs at install AND every boot) -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &plugdir;
mkdir -p &plugdir;/include
</INLINE>
</FILE>
""")

for rel, dest, mode in FILES:
    with open(os.path.join(SRC, rel), "r") as fh:
        content = cdata(fh.read())
    parts.append(f"""<FILE Name="{dest}" Mode="{mode}">
<INLINE>
<![CDATA[
{content}]]>
</INLINE>
</FILE>
""")

parts.append("""<!-- post-install -->
<FILE Run="/bin/bash">
<INLINE>
echo ""
echo "+=============================================================+"
echo "| vm.manager installed.                                       |"
echo "| Open it at:  Settings -> Utilities -> VM Manager            |"
echo "+=============================================================+"
echo ""
</INLINE>
</FILE>

<!-- uninstall handler -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
rm -rf &plugdir;
echo "vm.manager removed."
</INLINE>
</FILE>

</PLUGIN>
""")

with open(OUT, "w") as fh:
    fh.write("".join(parts))

print("wrote", OUT)
