export default {
    slug: 'menu-groups',
    category: 'stock',
    title: 'Set up the menu groups',
    summary: 'Build the tabs the cashier sees on the till (for example Lunch, with Standard Meal under it), hide or reorder them, and put products in them.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['You are signed in as a **Manager** or **Admin**.', 'You know how you want the till to be laid out, for example Breakfast, Lunch, Snacks and Beverages.'],
    steps: [
        {
            title: 'Open Menu groups',
            body: 'Open **Catalog**, then **Menu groups**. A **menu group** is a tab on the till, such as **Lunch**. A store with no menu groups shows the usual category tabs on the till, so nothing changes until you add the first group.',
            figure: {
                shot: 'groups-empty',
                alt: 'The Menu groups page with no groups yet and an Add group button.',
                marks: [
                    { type: 'look', target: 'empty', label: 'No groups yet', detail: 'Until you add a group, the till keeps showing category tabs.' },
                    { type: 'click', target: 'add', label: 'Click Add group', detail: 'Click Add group.' },
                ],
            },
            note: 'Categories are not replaced. They are still used to add up reports. Menu groups only decide how the cashier finds things on the till.',
        },
        {
            title: 'Add a group',
            body: 'Type the group’s name and click **Add group**. It becomes a tab on the till right away. Add as many as you need, one at a time.',
            figure: {
                shot: 'groups-add-form',
                alt: 'The Menu groups page with the new group name box filled in and an Add group button.',
                marks: [
                    { type: 'type', target: 'name', label: 'Group name', detail: 'Group name, for example:', text: 'Breakfast' },
                    { type: 'click', target: 'add', label: 'Click Add group', detail: 'Click Add group.' },
                ],
            },
        },
        {
            title: 'Add a sub-group',
            body: 'Click **Add sub-group** on the group’s row, type a name such as **Standard Meal**, and click **Add sub-group**. A sub-group shows as a smaller tab under its group on the till. A sub-group cannot have sub-groups of its own: there are only two levels.',
            figure: {
                shot: 'groups-add-sub',
                alt: 'A new sub-group name box under the Lunch group, with an Add sub-group button.',
                marks: [
                    { type: 'type', target: 'name', label: 'Sub-group name', detail: 'Sub-group name, for example:', text: 'Standard Meal' },
                    { type: 'click', target: 'add', label: 'Click Add sub-group', detail: 'Click Add sub-group.' },
                ],
            },
        },
        {
            title: 'Rename, reorder, hide',
            body: 'On each row: **↑** and **↓** move the group up or down among its neighbours (the till shows the tabs in this order), **Rename** changes its name, and **Hide** takes it off the till. A hidden group, and the sub-groups under it, disappear from the till. **Show** brings it back. Groups are never deleted.',
            figure: {
                shot: 'groups-tree',
                alt: 'The list of menu groups with their sub-groups, each row showing up and down arrows, Rename, Add sub-group and Hide.',
                marks: [
                    { type: 'click', target: 'up', label: 'Move up', detail: 'Click ↑ to move a group one place up. ↓ moves it down.' },
                    { type: 'click', target: 'rename', label: 'Rename', detail: 'Click Rename to change the name.' },
                    { type: 'click', target: 'hide', label: 'Hide', detail: 'Click Hide to take the group off the till.' },
                    { type: 'look', target: 'subgroups', label: 'Sub-groups', detail: 'Sub-groups sit indented under their group.' },
                ],
            },
            warning: 'Hiding a group does not hide its products from search. The cashier can still find them by typing the name or scanning the barcode.',
        },
        {
            title: 'See a hidden group',
            body: 'A hidden group stays in the list in grey with an **INACTIVE** badge, and its button reads **Show**. Click **Show** to put it back on the till.',
            figure: {
                shot: 'groups-hidden',
                alt: 'A hidden group shown in grey with a Show button.',
                marks: [
                    { type: 'look', target: 'badge', label: 'Inactive', detail: 'Grey text and an INACTIVE badge mean the cashier does not see this group.' },
                    { type: 'click', target: 'show', label: 'Click Show', detail: 'Click Show to bring the group back to the till.' },
                ],
            },
        },
        {
            title: 'Put a product in a group',
            body: 'Open **Catalog**, then **Products**, and click **Edit** on the product. Choose a **Menu group** from the list (a sub-group reads like **Lunch › Standard Meal**) and click **Save changes**. The product now shows under that tab on the till. Leave it on **None** and the product is found by search or barcode only, and by the **All** tab.',
            figure: {
                shot: 'groups-product-form',
                alt: 'The Edit product panel with the Menu group list open on Beverages.',
                marks: [
                    { type: 'click', target: 'group', label: 'Menu group', detail: 'Choose the group the product belongs under.' },
                    { type: 'click', target: 'save', label: 'Click Save changes', detail: 'Click Save changes.' },
                ],
            },
            note: 'The **Products** table has a **Menu group** column, so you can check at a glance which products are not in a group yet.',
        },
        {
            title: 'Check the finished list',
            body: 'Your groups and sub-groups are listed in till order. Meals are placed in a group from the **Meals** screen (see “Build a meal and its prices”).',
            figure: {
                shot: 'groups-tree',
                alt: 'The full list of menu groups.',
                marks: [{ type: 'look', target: 'list', label: 'Till order', detail: 'The till shows the tabs in this order, with sub-groups under their group.' }],
            },
        },
    ],
    done: 'The tabs are ready. Next, build the meals that appear under them.',
    next: ['build-meal', 'sell-meal'],
};
