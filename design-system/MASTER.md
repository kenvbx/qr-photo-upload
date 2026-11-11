# Gather design system

## Direction
Quiet, photo-first organizer workspace and an approachable one-task guest page. Flat cards, warm paper background, dark green actions, generous space. No external fonts or raster decorative assets required. System sans typography, large editorial headings, concise interface copy. Demo labels remain visible.

## Skill evidence and manual decisions
`ui-ux-pro-max` queries: `photo sharing mobile upload` returned an off-topic wellness style; narrowed `file management dashboard` returned a relevant Flat Design style but an education palette and academic typography that do not fit this app. Only the verified flat-design/general accessibility guidance is adopted. The warm green palette and system typography below are deliberate manual choices, not claimed product-specific database matches. Laravel stack search verified private storage and server-side upload validation guidance; Livewire-specific results do not apply to the selected Blade stack.

## Tokens
Ink #213d34; muted #65746c; paper #f7f8f3; card #ffffff; line #dce3da; primary #285c46 with white text; accent #c39a53 (decorative); error #a12b27. Base text 16 px; helper text 12 px minimum. Inputs and actions at least 44 px high. Cards 10–14 px radius. Visible 3 px keyboard focus outline.

## Layout and behavior
Organizer desktop: collection library plus create/share sidebar; mobile: sidebar before gallery so sharing and creation are immediately available. Guest: single column, visible limits and file picker, no dependence on dragging. 375/390 px mobile and 1440 px desktop checked; intermediate layouts use fluid grids and 780/1100 px breakpoints. Use success/error live regions, visible labels and upload busy state. Nonessential hover transitions respect reduced motion. Delete/close actions ask for confirmation.

## States
Empty collection, active/closed/expired link, validation errors, upload pending/success, login errors, missing resources, private photo access and deletion. Manual refresh is explicit. No fake testimonial, revenue, user count or client claim.
