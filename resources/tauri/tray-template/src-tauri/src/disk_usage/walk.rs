//! The parallel directory walk: a pool of scoped threads sharing a stack of
//! folders to read. Each folder becomes a [`Node`]; sizes are summed into
//! parents once the walk is done.

use super::tally::{FileRecord, Tally};
use super::{DirReader, Entry, EntryKind, FLAG_CLOUD, FLAG_INCOMPLETE, FLAG_NOT_FOLLOWED};
use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::{Condvar, Mutex};

const SEEN_SHARDS: usize = 64;

pub struct WalkOptions {
    pub threads: usize,
    pub top: usize,
    /// Folders read only after everything else, so hard-linked bytes are
    /// owned by their usual home (System32 rather than WinSxS).
    pub deferred: Vec<PathBuf>,
}

/// One folder. Before aggregation the sums are its own files only; after,
/// they cover everything beneath it.
#[derive(Debug, Clone, Default)]
pub struct Node {
    pub parent: Option<usize>,
    pub name: String,
    pub depth: u32,
    pub allocated: u64,
    pub logical: u64,
    pub files: u64,
    pub dirs: u64,
    pub flags: u32,
}

/// The finished walk.
pub struct Scan {
    /// Parents always come before their children.
    pub nodes: Vec<Node>,
    /// Largest files first, plus every file directly in the root.
    pub top_files: Vec<FileRecord>,
    pub tally: Tally,
}

struct WorkItem {
    node: usize,
    path: PathBuf,
    depth: u32,
}

/// Shared LIFO of folders still to read. `active` counts folders being
/// read, so idle workers know whether more work can still arrive.
struct WorkQueue {
    state: Mutex<(Vec<WorkItem>, usize)>,
    changed: Condvar,
}

impl WorkQueue {
    fn new(items: Vec<WorkItem>) -> Self {
        Self {
            state: Mutex::new((items, 0)),
            changed: Condvar::new(),
        }
    }

    fn pop(&self) -> Option<WorkItem> {
        let mut state = self.state.lock().expect("work queue poisoned");
        loop {
            if let Some(item) = state.0.pop() {
                state.1 += 1;
                return Some(item);
            }
            if state.1 == 0 {
                return None;
            }
            state = self.changed.wait(state).expect("work queue poisoned");
        }
    }

    fn finish(&self, children: Vec<WorkItem>) {
        let mut state = self.state.lock().expect("work queue poisoned");
        state.0.extend(children);
        state.1 -= 1;
        self.changed.notify_all();
    }
}

/// (volume, file id) pairs already counted, sharded to keep locks short.
struct SeenFiles {
    shards: Vec<Mutex<HashSet<(u64, u64)>>>,
}

impl SeenFiles {
    fn new() -> Self {
        Self {
            shards: (0..SEEN_SHARDS)
                .map(|_| Mutex::new(HashSet::new()))
                .collect(),
        }
    }

    /// True the first time a key is seen.
    fn insert(&self, key: (u64, u64)) -> bool {
        let mixed = (key.0 ^ key.1).wrapping_mul(0x9E37_79B9_7F4A_7C15);
        let shard = (mixed >> 58) as usize % SEEN_SHARDS;
        self.shards[shard]
            .lock()
            .expect("seen set poisoned")
            .insert(key)
    }
}

struct Shared<'a, R> {
    reader: &'a R,
    tree: Mutex<Vec<Node>>,
    seen: SeenFiles,
    deferred_paths: &'a [PathBuf],
    deferred: Mutex<Vec<WorkItem>>,
    top: usize,
}

pub fn walk<R: DirReader>(root: &Path, reader: &R, options: &WalkOptions) -> Scan {
    let shared = Shared {
        reader,
        tree: Mutex::new(vec![Node {
            name: root.to_string_lossy().into_owned(),
            ..Node::default()
        }]),
        seen: SeenFiles::new(),
        deferred_paths: &options.deferred,
        deferred: Mutex::new(Vec::new()),
        top: options.top,
    };
    let mut tally = Tally::new(options.top);

    let first = vec![WorkItem {
        node: 0,
        path: root.to_path_buf(),
        depth: 0,
    }];
    run_pool(&shared, first, options.threads, &mut tally);
    let deferred = std::mem::take(&mut *shared.deferred.lock().expect("deferred poisoned"));
    run_pool(&shared, deferred, options.threads, &mut tally);

    let mut nodes = shared.tree.into_inner().expect("tree poisoned");
    let mut top_files = tally.files_of_note();
    refresh_stale_sizes(root, reader, &mut nodes, &mut top_files);
    aggregate(&mut nodes);

    Scan {
        nodes,
        top_files,
        tally,
    }
}

fn run_pool<R: DirReader>(
    shared: &Shared<R>,
    items: Vec<WorkItem>,
    threads: usize,
    tally: &mut Tally,
) {
    if items.is_empty() {
        return;
    }
    let queue = WorkQueue::new(items);

    std::thread::scope(|scope| {
        let workers: Vec<_> = (0..threads.max(1))
            .map(|_| scope.spawn(|| worker(shared, &queue)))
            .collect();
        for handle in workers {
            tally.merge(handle.join().expect("disk usage worker panicked"));
        }
    });
}

fn worker<R: DirReader>(shared: &Shared<R>, queue: &WorkQueue) -> Tally {
    let mut tally = Tally::new(shared.top);
    while let Some(item) = queue.pop() {
        let children = read_folder(shared, &item, &mut tally);
        queue.finish(children);
    }
    tally
}

/// Count one folder's files and register its subfolders. Returns the
/// subfolders to read next.
fn read_folder<R: DirReader>(
    shared: &Shared<R>,
    item: &WorkItem,
    tally: &mut Tally,
) -> Vec<WorkItem> {
    let listing = shared.reader.read_dir(&item.path);
    let mut own = Node::default();
    let mut folders: Vec<Entry> = Vec::new();

    for entry in listing.entries {
        if entry.kind != EntryKind::File {
            folders.push(entry);
            continue;
        }
        if let Some(key) = entry.link_key {
            if !shared.seen.insert(key) {
                tally.deduped_bytes += entry.allocated;
                continue;
            }
        }
        own.allocated += entry.allocated;
        own.logical += entry.logical;
        own.files += 1;
        tally.count_file(item.node, item.depth == 0, &entry);
    }
    for (path, code) in &listing.errors {
        tally.errors.record(path, *code);
    }

    let mut children = Vec::new();
    let mut tree = shared.tree.lock().expect("tree poisoned");
    let node = &mut tree[item.node];
    node.allocated += own.allocated;
    node.logical += own.logical;
    node.files += own.files;
    if !listing.errors.is_empty() {
        node.flags |= FLAG_INCOMPLETE;
    }

    for folder in folders {
        let flags = match folder.kind {
            EntryKind::LinkNotFollowed | EntryKind::OtherVolume => FLAG_NOT_FOLLOWED,
            EntryKind::CloudDir => FLAG_CLOUD,
            EntryKind::Dir | EntryKind::File => 0,
        };
        if flags == FLAG_NOT_FOLLOWED {
            tally.reparse_skipped += 1;
        }
        let path = item.path.join(&folder.name);
        tree.push(Node {
            parent: Some(item.node),
            name: folder.name,
            depth: item.depth + 1,
            flags,
            ..Node::default()
        });
        if folder.kind != EntryKind::Dir {
            continue;
        }

        let child = WorkItem {
            node: tree.len() - 1,
            path,
            depth: item.depth + 1,
        };
        if is_deferred(&child.path, shared.deferred_paths) {
            shared
                .deferred
                .lock()
                .expect("deferred poisoned")
                .push(child);
        } else {
            children.push(child);
        }
    }

    children
}

fn is_deferred(path: &Path, deferred: &[PathBuf]) -> bool {
    let text = path.to_string_lossy();
    deferred
        .iter()
        .any(|candidate| candidate.to_string_lossy().eq_ignore_ascii_case(&text))
}

/// Directory listings can lag behind a file that is still growing (or a
/// hard link changed through another name), so the files we report are
/// re-read from the file itself and their folder corrected.
fn refresh_stale_sizes<R: DirReader>(
    root: &Path,
    reader: &R,
    nodes: &mut [Node],
    files: &mut [FileRecord],
) {
    for file in files.iter_mut() {
        let path = node_path(root, nodes, file.node).join(&file.name);
        let Some((allocated, logical)) = reader.refresh_size(&path) else {
            continue;
        };
        let node = &mut nodes[file.node];
        node.allocated = (node.allocated + allocated).saturating_sub(file.allocated);
        node.logical = (node.logical + logical).saturating_sub(file.logical);
        file.allocated = allocated;
        file.logical = logical;
    }
    files.sort_by(|a, b| {
        b.allocated
            .cmp(&a.allocated)
            .then_with(|| a.name.cmp(&b.name))
    });
}

pub fn node_path(root: &Path, nodes: &[Node], index: usize) -> PathBuf {
    match nodes[index].parent {
        None => root.to_path_buf(),
        Some(parent) => node_path(root, nodes, parent).join(&nodes[index].name),
    }
}

/// Sum every node into its parent. Children always follow their parent, so
/// one pass from the end covers whole subtrees.
fn aggregate(nodes: &mut [Node]) {
    for index in (1..nodes.len()).rev() {
        let child = &nodes[index];
        let Some(parent) = child.parent else {
            continue;
        };
        let (allocated, logical, files, dirs) =
            (child.allocated, child.logical, child.files, child.dirs);
        let parent = &mut nodes[parent];
        parent.allocated += allocated;
        parent.logical += logical;
        parent.files += files;
        parent.dirs += dirs + 1;
    }
}
