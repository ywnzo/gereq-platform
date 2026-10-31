export const publicAccountActions = new Set(['login','signup','verify-email','request-password-reset','reset-password']);
export function parseCustomerAction(hash){const match=/^#customer-view\/(verify|reset)\/([a-f0-9]{64})$/i.exec(hash);return match?{kind:match[1].toLowerCase(),token:match[2]}:null;}
export function needsEmailVerification(session){return session?.authenticated===true&&session.account?.email_verified!==true;}
