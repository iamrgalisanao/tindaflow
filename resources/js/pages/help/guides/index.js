import addProducts from './addProducts';
import approveVoidRefund from './approveVoidRefund';
import cashDrawer from './cashDrawer';
import closeBusinessDay from './closeBusinessDay';
import closeShift from './closeShift';
import editProduct from './editProduct';
import enrollTerminal from './enrollTerminal';
import findSale from './findSale';
import glossary from './glossary';
import importProducts from './importProducts';
import makeSale from './makeSale';
import manageUsers from './manageUsers';
import manualDiscount from './manualDiscount';
import openShift from './openShift';
import packsBarcodes from './packsBarcodes';
import payOther from './payOther';
import receiveStock from './receiveStock';
import records from './records';
import reports from './reports';
import requestVoidRefund from './requestVoidRefund';
import salesHistory from './salesHistory';
import seniorPwd from './seniorPwd';
import setupStore from './setupStore';
import signIn from './signIn';
import stockCount from './stockCount';
import transferStock from './transferStock';
import troubleshooting from './troubleshooting';

/** The order here is the order on the Help page. */
export const CATEGORIES = [
    { id: 'start', label: 'Getting started', note: 'Sign in and find your way around.' },
    { id: 'setup', label: 'Set up the store', note: 'One-time work for an admin, before the first sale.' },
    { id: 'till', label: 'At the till', note: 'Everything a cashier does, from the first sale to closing the shift.' },
    { id: 'stock', label: 'Products & stock', note: 'Keep the catalog and the shelf count true.' },
    { id: 'manage', label: 'Running the store', note: 'Decide on voids and refunds, read reports, close the day.' },
    { id: 'trouble', label: 'When something looks wrong', note: 'What the messages and the words mean.' },
];

/** Within a category the order below is the order to read them in. */
const ALL = [
    signIn,
    setupStore,
    enrollTerminal,
    manageUsers,
    openShift,
    makeSale,
    payOther,
    seniorPwd,
    manualDiscount,
    findSale,
    requestVoidRefund,
    cashDrawer,
    closeShift,
    addProducts,
    importProducts,
    editProduct,
    packsBarcodes,
    receiveStock,
    stockCount,
    transferStock,
    approveVoidRefund,
    salesHistory,
    reports,
    closeBusinessDay,
    records,
    troubleshooting,
    glossary,
];

/** Each guide file names its category by id; the label is what the pages show. */
export const GUIDES = ALL.map((guide) => ({ ...guide, category: CATEGORIES.find((category) => category.id === guide.category)?.label ?? guide.category }));

export const findGuide = (slug) => GUIDES.find((guide) => guide.slug === slug) ?? null;

export const guidesAfter = (guide) => (guide.next ?? []).map(findGuide).filter(Boolean);
