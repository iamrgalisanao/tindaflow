export default {
    slug: 'manage-users',
    category: 'setup',
    title: 'Add and manage people',
    summary: 'Give each cashier, manager or admin their own sign-in, change a role or password, and switch someone off when they leave.',
    roles: ['ADMIN'],
    startHere: ['ADMIN'],
    minutes: 6,
    before: ['You are signed in as an **Admin**. Only admins can manage users.', 'You know the person’s name and email address, and which role they need.'],
    steps: [
        {
            title: 'Open Users',
            body: 'Click **Users** in the menu. The table lists everyone who can sign in, with their role, how much they can do (**ACCESS**) and whether they are **ACTIVE**. Use the **Role** and **Status** filters to narrow the list.',
            figure: {
                shot: 'users-list',
                alt: 'The Users page with a New user button, filters and a table of people.',
                marks: [
                    { type: 'look', target: 'table', label: 'Everyone who can sign in', detail: 'Each row is one person with their own sign-in.' },
                    { type: 'click', target: 'new-user', label: 'Click New user', detail: 'Click New user to add someone.' },
                ],
            },
        },
        {
            title: 'Type their name and email',
            body: 'In the **New user** panel, type the person’s **Name** and **Email**. They will sign in with this email, and it must not be used by anyone else in your store.',
            figure: {
                shot: 'users-form-top',
                alt: 'The New user panel with Name and Email filled in.',
                marks: [
                    { type: 'type', target: 'name', label: 'Name', detail: 'Their name:', text: 'Marco Reyes' },
                    { type: 'type', target: 'email', label: 'Email', detail: 'Their email address:', text: 'marco@example.com' },
                ],
            },
        },
        {
            title: 'Choose a role and a password',
            body: 'Pick the **Role**. Then give them a **Password** of at least 8 characters. **Generate** makes a strong one for you and **Show** lets you read it so you can pass it on. Click **Create user**.',
            figure: {
                shot: 'users-form-bottom',
                alt: 'The lower part of the New user panel with three role cards, a password box and a Create user button.',
                marks: [
                    { type: 'click', target: 'role', label: 'Pick the role', detail: 'Pick a role. Admin can do everything, including managing users. Manager runs the store day to day. Cashier rings up sales.' },
                    { type: 'click', target: 'generate', label: 'Generate a password', detail: 'Click Generate for a strong password, or type your own.' },
                    { type: 'click', target: 'create', label: 'Click Create user', detail: 'Click Create user.' },
                ],
            },
            warning: 'Share the password securely, in person or by a private message. Never post it in a group chat. Everything a person does is recorded under their name.',
        },
        {
            title: 'Check the new person is listed',
            body: 'The new person appears in the table as **ACTIVE**. Ask them to sign in and change nothing else. They can start work straight away.',
            figure: {
                shot: 'users-created',
                alt: 'The Users table now showing the new manager and cashier.',
                marks: [{ type: 'look', target: 'table', label: 'New people listed', detail: 'New users appear here straight away, each with their role and access.' }],
            },
        },
        {
            title: 'Change a role or set a new password',
            body: 'On the person’s row click **Edit**. You can change their **Role** or type a **New password** (leave it empty to keep the old one). The panel lists what the role allows. Click **Save changes**.',
            figure: {
                shot: 'users-edit',
                alt: 'The Edit user panel with a role, a new password box and the access list.',
                marks: [
                    { type: 'click', target: 'role', label: 'Change the role', detail: 'Choose a different role if their duties changed.' },
                    { type: 'look', target: 'access', label: 'What they can do', detail: 'This list shows what the role allows. It updates after you save.' },
                    { type: 'click', target: 'save', label: 'Click Save changes', detail: 'Click Save changes.' },
                ],
            },
            note: 'You cannot change your own role, and you cannot change the role of the only active admin. That keeps the store from locking itself out.',
        },
        {
            title: 'Switch someone off when they leave',
            body: 'Click **Deactivate** on their row, then confirm. They are signed out and cannot sign in again until you reactivate them. Their past sales and records stay exactly as they were, because users are never deleted.',
            figure: {
                shot: 'users-deactivate',
                alt: 'A confirmation asking whether to deactivate a user.',
                marks: [
                    { type: 'look', target: 'dialog', label: 'Confirm first', detail: 'You are asked to confirm, so a slip of the mouse does nothing.' },
                    { type: 'click', target: 'confirm', label: 'Click Deactivate', detail: 'Click Deactivate to confirm. Click Cancel instead if it was a slip.' },
                ],
            },
            dos: ['Deactivate people the same day they stop working for you.'],
            donts: ['Don’t share one sign-in between two people. Reports and the audit log would show the wrong name.'],
        },
    ],
    done: 'Your people can sign in. Cashiers will want the guide to starting their shift.',
    next: ['open-shift', 'add-products'],
};
