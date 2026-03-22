# DN Community Chat

## 1. Objective

Implement a thin private community chat module inside the existing Decent Newsroom product.

The module is intended for live testing with real users in bounded, supervised communities such as families, clubs, classrooms, or youth groups.

The module must remain narrow in scope and must not expand into a general public chat product.

---

## 2. Product shape

The product is:

* invite-only
* community-scoped by subdomain
* backed by a separate private relay
* text-only
* based on custodial Nostr identities
* session-based for end users
* supervised by admins/guardians
* limited to chats the user is a member of

The module is not:

* public
* open signup
* end-to-end private from the operator
* media-centric
* federated across public relays

---

## 3. Core model

### 3.1 Community

A community is the top-level scope.

A community is identified by hostname.

Examples:

* `community.decentnewsroom.com`
* `oakclub.decentnewsroom.com`

A user must belong to a community to access it.

### 3.2 Group

A group is a chat space inside a community.

Groups are accessed as scoped routes within the community.

Example:

* `/groups/<groupSlug>`

A user must belong to a group to see or use it.

### 3.3 User

A user is an application account with a custodial Nostr identity.

The system generates and stores the user’s Nostr keypair.

The user does not manage keys directly.

### 3.4 Session

A user accesses the app through a long-lived login session created by redeeming an invite link.

### 3.5 Private relay

Community chat traffic must use a separate private relay instance.

Do not use the existing public-readable relay for this module.

---

## 4. Roles

### 4.1 System admin

Global operator for a community.

Can:

* create users
* generate keys
* create, revoke, and inspect invites
* create and manage groups
* assign roles
* view all chats in the community
* revoke sessions
* inspect relay status

### 4.2 Guardian / group admin

Scoped supervisor.

Can:

* distribute scoped invites
* manage membership in their scope
* view chats in their scope

### 4.3 User

Regular participant.

Can:

* redeem invite link
* maintain login session
* access only their community
* access only groups they belong to
* send text messages
* edit own profile
* view only allowed contacts/users
* create chats only if policy allows

---

## 5. User-visible surfaces

## 5.1 Community app surface

Main routes:

* `/activate/{token}`
* `/`
* `/groups`
* `/groups/{groupSlug}`
* `/contacts`
* `/profile`
* `/settings`

Optional later:

* direct chat routes

## 5.2 Admin surface

Main routes:

* `/admin`
* `/admin/users`
* `/admin/groups`
* `/admin/invites`
* `/admin/sessions`
* `/admin/relay`
* `/admin/chats`

## 5.3 Scoped management surface

Main routes:

* `/manage/groups/{groupSlug}`
* `/manage/groups/{groupSlug}/members`
* `/manage/invites`

---

## 6. Required flows

## 6.1 Community resolution flow

When a request arrives, the application resolves the current community from the request host.

Required behavior:

* if host maps to a valid community, continue
* if not, fail closed
* all later access checks must be scoped to the resolved community

## 6.2 Admin creates user flow

1. Admin creates a user inside a community.
2. System generates a custodial Nostr keypair.
3. System stores the encrypted private key.
4. System prepares relay access for that identity if needed.
5. System creates an invite token.
6. Admin copies or distributes the invite link.

Result:

* user exists
* identity exists
* invite exists
* user is not yet active until invite is redeemed

## 6.3 Invite activation flow

1. User opens an invite link.
2. System validates the token.
3. System checks expiry, revocation, and usage limits.
4. System grants activation or scoped membership according to invite type.
5. System creates a long-lived session.
6. System redirects the user into the community app.

Result:

* token is consumed or usage count updated
* session becomes active
* user gains access only to allowed scopes

## 6.4 Session restoration flow

1. User revisits the app.
2. System checks the session cookie.
3. If valid, user continues directly.
4. If invalid, expired, or revoked, access is denied and reactivation is required.

## 6.5 Group listing flow

1. User opens `/groups`.
2. System resolves current community.
3. System resolves current user session.
4. System returns only groups the user can access.

The response must not leak hidden groups.

## 6.6 Group access flow

1. User opens `/groups/{groupSlug}`.
2. System confirms the group belongs to the current community.
3. System confirms the user is a current member.
4. System loads message history and group metadata.

If the user is not a member, access is denied.

## 6.7 Send message flow

1. User enters text in a group chat.
2. System validates session.
3. System validates community access.
4. System validates group membership.
5. System constructs a Nostr event.
6. System signs the event with the user’s custodial identity.
7. System publishes the event to the private relay.
8. System updates any local message index if used.
9. System updates the UI through the chosen real-time path.

## 6.8 Read message history flow

1. User opens a group.
2. System validates session and membership.
3. System returns the group’s message history from relay-backed storage, local index, or both.

Users must never receive messages from groups they do not belong to.

## 6.9 Edit profile flow

1. User opens `/profile`.
2. User edits allowed profile fields.
3. System validates session.
4. System constructs a kind `0` profile event.
5. System signs and publishes the event.
6. System updates local cached profile state if used.

## 6.10 Admin group management flow

Admin can:

* create a group
* archive a group
* inspect members
* add/remove members
* assign scoped roles

All operations are community-scoped.

## 6.11 Scoped invite flow

An admin or guardian/group-admin may create or distribute an invite depending on policy.

Invite types may include:

* user activation invite
* group join invite
* seat invite

Invite scope must always be explicit.

## 6.12 Session revocation flow

An admin may revoke a user session.

Required behavior:

* revoked session becomes unusable immediately or at next validation point
* user must no longer access the community with that session

---

## 7. Visibility rules

These rules are mandatory.

### 7.1 Community visibility

A user may only access communities they belong to.

### 7.2 Group visibility

A user may only see groups they belong to.

### 7.3 Chat visibility

A user may only see messages from groups they belong to.

### 7.4 Contact visibility

Ordinary users should only see:

* users in shared groups
* or users explicitly approved by policy

Do not expose a full global user directory by default.

### 7.5 Admin visibility

System admins may see all groups and chats in their community.

### 7.6 Guardian/group-admin visibility

Guardians/group-admins may only see users, groups, and chats within their assigned scope.

---

## 8. Data concepts

Implementation may use any internal schema, but the following concepts must exist.

### 8.1 Community

Represents one host-scoped chat environment.

Must include:

* identifier
* host
* name
* status
* relay target

### 8.2 User

Represents an application account.

Must include:

* identifier
* display name
* status
* activation state

### 8.3 Community membership

Represents a user’s membership and role in a community.

### 8.4 Custodial Nostr identity

Represents the user’s Nostr pubkey and encrypted private key.

### 8.5 Group

Represents a chat group inside a community.

Must include:

* identifier
* community reference
* slug
* name
* status

### 8.6 Group membership

Represents a user’s role and membership state inside a group.

### 8.7 Invite

Represents a high-entropy activation or join token.

Must include:

* scope
* creator
* type
* role to grant
* expiry
* revocation state
* usage state

### 8.8 Session

Represents a long-lived login session.

Must include:

* user reference
* community reference
* session state
* expiry
* revocation state

### 8.9 Message index

Optional but recommended.

Represents locally indexed relay messages for faster access and admin tooling.

---

## 9. Event model

The product is Nostr-based, but the application owns the user-facing semantics.

### 9.1 Profile events

Use kind `0` for profile metadata.

Supported profile fields initially:

* display name
* about

### 9.2 Chat events

Use NIP-28 kind 42 (channel message) for chat text messages.
See NIP/28.md for details.

Channel setup and moderation use NIP-28:
- Kind 40 for channel creation
- Kind 41 for channel metadata
- Kind 42 for channel messages
- Kind 43 for hiding messages
- Kind 44 for muting users

Kind 42 messages are linked to their channel via an `e` tag
referencing the kind 40 channel creation event.

### 9.3 Signing model

The backend signs events on behalf of the user using the user’s custodial identity.

The browser does not manage Nostr keys directly in v1.

---

## 10. Privacy and supervision model

The following must be true:

* the module uses a separate private relay
* the module is not publicly readable by default
* the operator is allowed to supervise according to role policy
* this is not an operator-blind system
* community/group membership is enforced at the application level

This must be reflected consistently in UI behavior and access control.

---

## 11. Security requirements

### 11.1 Invite security

Invite tokens must be:

* high-entropy
* stored hashed
* expirable
* revocable
* usage-limited where appropriate

### 11.2 Session security

Sessions must be:

* long-lived
* cookie-based
* revocable
* validated server-side

### 11.3 Key custody

Private keys must be:

* encrypted at rest
* decrypted only for signing
* excluded from logs and user-facing output

### 11.4 Authorization

Every read and write path must validate:

* current community
* current session
* required membership
* required role

### 11.5 Fail-closed behavior

If any of the above checks fail, access must be denied.

---

## 12. Deployment shape

The module must be implemented inside the existing DN deployment, but with a separate relay boundary.

Required shape:

* existing DN application remains the main product codebase
* community chat runs as a community-scoped subdomain surface
* a second private relay instance handles community chat traffic
* the existing public relay is not reused for this private module

This is a technical boundary, not a separate product line.

---

## 13. Non-goals

Do not implement the following as part of this spec:

* media uploads
* public onboarding
* public chat discovery
* public-readable relay storage for community messages
* external-client compatibility as a primary requirement
* complex social features unrelated to core private chat flows

---

## 14. Acceptance criteria

The implementation is acceptable when all of the following are true:

1. A community can be resolved by subdomain.
2. An admin can create a user and issue an invite.
3. A user can activate access through an invite link.
4. A long-lived session is created and restored correctly.
5. A user only sees groups they belong to.
6. A user only sees chat history for groups they belong to.
7. A user can send text messages into an allowed group.
8. Messages are signed and published through the user’s custodial Nostr identity.
9. A user can edit their profile as a kind `0` event.
10. An admin can inspect users, groups, invites, sessions, and chats.
11. Scoped supervisors can only act within their scope.
12. Community chat traffic uses a separate private relay boundary.
13. No ordinary user path leaks hidden groups, hidden users, or hidden chats.
14. Failed authorization paths deny access cleanly.

---

## 15. Implementation directive

Implement the narrowest version that satisfies the full flows above.

Prefer:

* bounded scope
* clear authorization
* minimal UI surface
* operator clarity
* privacy through real infrastructure separation
* product consistency with existing DN deployment

Do not expand the module beyond the stated private community chat purpose during v1.
