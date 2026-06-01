#!/usr/bin/env python3
"""Render a BEFORE/AFTER database-state comparison for the contact-merge smoke report."""
import json, sys

before = json.load(open(sys.argv[1]))
after = json.load(open(sys.argv[2]))
out = sys.argv[3]


def cell(val, changed):
    disp = "<span class='null'>null</span>" if val is None else ("<span class='empty'>(empty)</span>" if val == "" else str(val))
    cls = "chg" if changed else ""
    return f"<td class='{cls}'>{disp}</td>"


def row(label, b, a, fields):
    tds_b = "".join(cell(b.get(f), False) for f in fields)
    tds_a = "".join(cell(a.get(f), b.get(f) != a.get(f)) for f in fields)
    return f"<tr><th>{label}</th>{tds_b}<td class='gap'>→</td>{tds_a}</tr>"


cfields = ["id", "email", "first_name", "last_name", "County", "Role", "history_events", "deleted_at"]
afields = ["id", "name", "contact_id", "order_id"]

html = f"""<!doctype html><html><head><meta charset=utf-8><style>
body{{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#fff;margin:0;padding:28px;color:#1f2933}}
h1{{font-size:20px;margin:0 0 4px}} .sub{{color:#6b7280;margin:0 0 20px;font-size:13px}}
h2{{font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:22px 0 8px}}
table{{border-collapse:collapse;width:100%;margin-bottom:8px;font-size:13px}}
th,td{{border:1px solid #e5e7eb;padding:6px 10px;text-align:left;vertical-align:top}}
thead th{{background:#f9fafb;font-weight:600;color:#374151}}
tbody th{{background:#f3f4f6;font-weight:600;white-space:nowrap}}
.split{{background:#ede9fe;font-weight:700;text-align:center;color:#6d28d9}}
td.chg{{background:#dcfce7;font-weight:700;color:#166534}}
td.gap{{background:#f9fafb;text-align:center;color:#9ca3af;font-weight:700}}
.null{{color:#9ca3af;font-style:italic}} .empty{{color:#c026d3;font-style:italic}}
.legend{{font-size:12px;color:#6b7280;margin-top:10px}} .legend b{{color:#166534}}
</style></head><body>
<h1>Contact merge — database before &amp; after</h1>
<p class=sub>Merge duplicate <b>#{before['duplicate']['id']}</b> into survivor <b>#{before['survivor']['id']}</b>.
Green = value changed by the merge.</p>

<h2>contacts</h2>
<table><thead><tr><th></th><th colspan={len(cfields)} class=split>BEFORE</th><th class=split>→</th><th colspan={len(cfields)} class=split>AFTER</th></tr>
<tr><th></th>{''.join(f'<th>{f}</th>' for f in cfields)}<th></th>{''.join(f'<th>{f}</th>' for f in cfields)}</tr></thead><tbody>
{row('survivor', before['survivor'], after['survivor'], cfields)}
{row('duplicate', before['duplicate'], after['duplicate'], cfields)}
</tbody></table>

<h2>attendees (the attendee "merged away" from the duplicate)</h2>
<table><thead><tr><th></th><th colspan={len(afields)} class=split>BEFORE</th><th class=split>→</th><th colspan={len(afields)} class=split>AFTER</th></tr>
<tr><th></th>{''.join(f'<th>{f}</th>' for f in afields)}<th></th>{''.join(f'<th>{f}</th>' for f in afields)}</tr></thead><tbody>
{row('attendee', before['attendee'], after['attendee'], afields)}
</tbody></table>

<p class=legend>Key changes: the attendee's <b>contact_id</b> moves from the duplicate to the survivor (its order history follows);
the survivor's empty <b>last_name</b> and <b>County</b> are gap-filled from the duplicate; the survivor gains a merge
<b>history</b> entry; and the duplicate's <b>deleted_at</b> is set (soft-deleted, so its email frees up).</p>
</body></html>"""

open(out, "w").write(html)
print(f"wrote {out}")
