### Features

- A notification about one new document now says which of your saved searches found it, such as *Found by your saved search: tag:unpaid*, so that you can tell why it came and which search to correct when one finds too much. It names the first of your searches that found the document, in the order in which you saved them; a document that only **Every new document that I can open** brought names none, and notifications that went out before the update read as they did.

### Bug fixes

- A Paperless that stops answering while the notifications about new documents ask it, as it may while it takes in new documents, no longer keeps the background job waiting for each saved search of every user, ten seconds each: after three saved searches in a row without an answer, such as a timeout or a failed connection, or once the saved searches of a run have taken a minute, the run stops asking and waits as after any other failure. The documents keep waiting, and the next run asks again. One search that is too expensive for Paperless to answer in time still stops nobody else's notifications.
