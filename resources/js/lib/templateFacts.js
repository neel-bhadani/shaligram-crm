/*
 | What 11za says about the template behind a tag, in words. One copy, so the
 | Tags tab, the tag form, the lead's WhatsApp section and the bulk send say
 | the same thing.
 */

export const approvalLabel = status => ({
  APPROVED: 'Approved',
  PENDING: 'Pending approval',
  REJECTED: 'Rejected',
}[status] ?? (status ? status.charAt(0) + status.slice(1).toLowerCase() : ''))

export const categoryLabel = category => ({
  MARKETING: 'Marketing',
  UTILITY: 'Utility',
  AUTHENTICATION: 'Authentication',
}[category] ?? (category ? category.charAt(0) + category.slice(1).toLowerCase() : ''))

export const marketingNote = 'Marketing template: Meta limits how many marketing messages a person '
  + 'receives and lets them switch them off, so 11za can accept it and it may never be delivered.'
