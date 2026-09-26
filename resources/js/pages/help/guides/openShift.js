export default {
    slug: 'open-shift',
    category: 'till',
    title: 'Start your shift',
    summary: 'Open the till, tell the system how much cash is in the drawer, and learn your way around the register screen.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    startHere: ['CASHIER'],
    minutes: 4,
    before: [
        'You are signed in with your own account.',
        'The till computer has been enrolled by an admin (see “Enroll a till”).',
        'You have counted the cash in the drawer: this is your **opening cash**.',
    ],
    steps: [
        {
            title: 'Open the till',
            body: 'On the **Dashboard** click **Open the till**. You can also use **POS / Till** in the menu.',
            figure: {
                shot: 'signin-cashier',
                alt: 'A cashier’s Dashboard with the Open the till button at the top right.',
                marks: [{ type: 'click', target: 'open-till', label: 'Click Open the till', detail: 'Click Open the till.' }],
            },
        },
        {
            title: 'Type the opening cash and open the shift',
            body: 'A **shift** is your time at the till. Type the cash in the drawer into **Opening cash**, then click **Open shift**. The system uses this number at the end of the day to check your drawer.',
            figure: {
                shot: 'till-open-shift',
                alt: 'The Open a shift form with an Opening cash box and an Open shift button.',
                marks: [
                    { type: 'type', target: 'cash', label: 'Opening cash', detail: 'Opening cash, the money in the drawer right now:', text: '500.00' },
                    { type: 'click', target: 'open', label: 'Click Open shift', detail: 'Click Open shift.' },
                ],
            },
            dos: ['Count the drawer before you type. A wrong opening figure makes your closing count look wrong.'],
            donts: ['Don’t open a shift for someone else. A shift belongs to the person who opened it, and every sale on it is recorded under their name.'],
        },
        {
            title: 'Meet the register',
            body: 'This is where you sell. The **cart** is on the left. The **products** are on the right, with a search box above them. The four buttons at the top switch between **Register**, **Payment**, **Lookup** and **Shift**.',
            figure: {
                shot: 'till-register-empty',
                alt: 'The register screen with an empty cart on the left and product tiles and a search box on the right.',
                marks: [
                    { type: 'look', target: 'header', label: 'Your till and shift', detail: 'The top bar shows which till this is, who is selling and when the shift started.' },
                    { type: 'look', target: 'cart', label: 'The cart', detail: 'Everything for the current customer goes here, with the total at the bottom.' },
                    { type: 'look', target: 'products', label: 'Products', detail: 'Tap a tile to add that product. Use the search box to scan or find one.' },
                    { type: 'look', target: 'tabs', label: 'Four places', detail: 'Register is for selling. Payment opens by itself when you tap Charge. Lookup finds old sales. Shift is for the cash drawer and closing.' },
                ],
            },
            tip: 'The cursor is already in the search box, so a barcode scanner works the moment you open the till.',
        },
    ],
    done: 'Your shift is open. Time to ring up your first sale.',
    next: ['make-sale', 'cash-drawer'],
};
