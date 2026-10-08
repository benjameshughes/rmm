//! Turns a finished [`Scan`] into the `rmm.du/1` JSON line: prune the folder
//! tree to what is worth showing, then shrink it until it fits the budget.

use super::keep_pattern::KeepPattern;
use super::walk::{Node, Scan};
use super::{Volume, FLAG_CLOUD, FLAG_INCOMPLETE, FLAG_KEPT, FLAG_NOT_FOLLOWED, FLAG_OTHER};
use crate::config::{AGENT_VERSION, DU_JSON_BUDGET_CHARS, DU_MAX_NODES, DU_TOP_EXTENSIONS};
use serde::Serialize;
use std::collections::BTreeMap;

pub const SCHEMA: &str = "rmm.du/1";

/// Flags a rolled-up "*" node inherits from the folders inside it.
const INHERITED_FLAGS: u32 = FLAG_NOT_FOLLOWED | FLAG_INCOMPLETE | FLAG_CLOUD;

pub struct ReportOptions {
    pub root: String,
    pub max_depth: u32,
    pub min_bytes: u64,
    pub keep: Vec<KeepPattern>,
    pub volume: Option<Volume>,
    pub started_at: String,
    pub duration_ms: u64,
}

/// [parent_index, name, allocated, logical, files, dirs, flags]
type NodeRow<'a> = (i64, &'a str, u64, u64, u64, u64, u32);
/// [node_index_of_parent_dir, name, allocated, logical, modified, attributes]
type FileRow = (usize, String, u64, u64, String, u32);

#[derive(Serialize)]
struct Report<'a> {
    schema: &'static str,
    root: &'a str,
    agent: &'static str,
    started_at: &'a str,
    duration_ms: u64,
    volume: Option<VolumeJson<'a>>,
    totals: Totals,
    nodes: &'a [NodeRow<'a>],
    top_files: Vec<FileRow>,
    extensions: Vec<(&'a str, u64, u64)>,
    errors: Errors<'a>,
}

#[derive(Serialize)]
struct VolumeJson<'a> {
    total: u64,
    free: u64,
    fs: &'a str,
}

#[derive(Serialize)]
struct Totals {
    allocated: u64,
    logical: u64,
    files: u64,
    dirs: u64,
    hardlink_bytes_deduped: u64,
    cloud_logical: u64,
    reparse_skipped: u64,
    unaccounted: Option<i64>,
}

#[derive(Serialize)]
struct Errors<'a> {
    count: u64,
    sample: &'a [(String, i32)],
}

/// The nodes that survive one pruning pass.
struct Pruned<'a> {
    rows: Vec<NodeRow<'a>>,
    /// Old node index -> row index, for visible nodes.
    position: Vec<Option<usize>>,
}

/// Render the report, doubling the size threshold until it fits.
pub fn render(scan: &Scan, options: &ReportOptions) -> String {
    let (matched, kept) = keep_marks(&scan.nodes, &options.keep);
    let root_allocated = scan.nodes[0].allocated;
    let mut min_bytes = options.min_bytes;

    loop {
        let pruned = prune(&scan.nodes, options.max_depth, min_bytes, &matched, &kept);
        let json = serialize(scan, options, &pruned);
        let fits = json.len() <= DU_JSON_BUDGET_CHARS && pruned.rows.len() <= DU_MAX_NODES;
        if fits || min_bytes > root_allocated {
            return json;
        }
        min_bytes = min_bytes.saturating_mul(2).max(1);
    }
}

pub fn iso8601(unix_seconds: i64) -> String {
    chrono::DateTime::from_timestamp(unix_seconds, 0)
        .unwrap_or_default()
        .format("%Y-%m-%dT%H:%M:%SZ")
        .to_string()
}

/// (matches a pattern, matches or holds a match) per node.
fn keep_marks(nodes: &[Node], patterns: &[KeepPattern]) -> (Vec<bool>, Vec<bool>) {
    let mut matched = vec![false; nodes.len()];
    let mut kept = vec![false; nodes.len()];

    for index in 1..nodes.len() {
        let depth = nodes[index].depth as usize;
        if !patterns.iter().any(|pattern| pattern.depth() == depth) {
            continue;
        }
        let names = names_below_root(nodes, index);
        if !patterns.iter().any(|pattern| pattern.matches(&names)) {
            continue;
        }
        matched[index] = true;
        let mut current = Some(index);
        while let Some(at) = current.filter(|&at| !kept[at]) {
            kept[at] = true;
            current = nodes[at].parent;
        }
    }

    (matched, kept)
}

fn names_below_root(nodes: &[Node], index: usize) -> Vec<&str> {
    let mut names = Vec::new();
    let mut current = index;
    while let Some(parent) = nodes[current].parent {
        names.push(nodes[current].name.as_str());
        current = parent;
    }
    names.reverse();
    names
}

fn prune<'a>(
    nodes: &'a [Node],
    max_depth: u32,
    min_bytes: u64,
    matched: &[bool],
    kept: &[bool],
) -> Pruned<'a> {
    let mut position: Vec<Option<usize>> = vec![None; nodes.len()];
    let mut rows: Vec<NodeRow<'a>> = Vec::new();
    let mut others: BTreeMap<usize, Node> = BTreeMap::new();
    let mut has_visible_child = Vec::new();

    for (index, node) in nodes.iter().enumerate() {
        let parent_row = node.parent.and_then(|parent| position[parent]);
        let is_root = node.parent.is_none();
        let is_large = node.depth <= max_depth && node.allocated >= min_bytes;
        let is_visible = is_root || kept[index] || (parent_row.is_some() && is_large);

        if !is_visible {
            if let Some(row) = parent_row {
                let other = others.entry(row).or_default();
                other.allocated += node.allocated;
                other.logical += node.logical;
                other.files += node.files;
                other.dirs += node.dirs + 1;
                other.flags |= node.flags & INHERITED_FLAGS;
            }
            continue;
        }

        if let Some(row) = parent_row {
            has_visible_child[row] = true;
        }
        let flags = node.flags | if matched[index] { FLAG_KEPT } else { 0 };
        position[index] = Some(rows.len());
        has_visible_child.push(false);
        rows.push((
            parent_row.map_or(-1, |row| row as i64),
            node.name.as_str(),
            node.allocated,
            node.logical,
            node.files,
            node.dirs,
            flags,
        ));
    }

    for (row, other) in others {
        if !has_visible_child[row] {
            continue;
        }
        rows.push((
            row as i64,
            "*",
            other.allocated,
            other.logical,
            other.files,
            other.dirs,
            FLAG_OTHER | other.flags,
        ));
    }

    Pruned { rows, position }
}

fn serialize(scan: &Scan, options: &ReportOptions, pruned: &Pruned<'_>) -> String {
    let root = &scan.nodes[0];
    let tally = &scan.tally;
    let unaccounted = options
        .volume
        .as_ref()
        .filter(|volume| volume.is_volume_root)
        .map(|volume| volume.total as i64 - volume.free as i64 - root.allocated as i64);

    let report = Report {
        schema: SCHEMA,
        root: &options.root,
        agent: AGENT_VERSION,
        started_at: &options.started_at,
        duration_ms: options.duration_ms,
        volume: options.volume.as_ref().map(|volume| VolumeJson {
            total: volume.total,
            free: volume.free,
            fs: &volume.fs,
        }),
        totals: Totals {
            allocated: root.allocated,
            logical: root.logical,
            files: root.files,
            dirs: root.dirs,
            hardlink_bytes_deduped: tally.deduped_bytes,
            cloud_logical: tally.cloud_logical,
            reparse_skipped: tally.reparse_skipped,
            unaccounted,
        },
        nodes: &pruned.rows,
        top_files: file_rows(scan, pruned),
        extensions: extension_rows(scan),
        errors: Errors {
            count: tally.errors.count,
            sample: &tally.errors.sample,
        },
    };

    serde_json::to_string(&report).expect("disk usage report always serializes")
}

/// Each file hangs off its nearest listed folder; folders pruned in between
/// are kept in its name, e.g. `ben\Downloads\disk.iso`.
fn file_rows(scan: &Scan, pruned: &Pruned) -> Vec<FileRow> {
    let separator = std::path::MAIN_SEPARATOR_STR;

    scan.top_files
        .iter()
        .map(|file| {
            let mut names = vec![file.name.as_str()];
            let mut folder = file.node;
            while pruned.position[folder].is_none() {
                names.push(scan.nodes[folder].name.as_str());
                folder = scan.nodes[folder].parent.unwrap_or(0);
            }
            names.reverse();

            (
                pruned.position[folder].unwrap_or(0),
                names.join(separator),
                file.allocated,
                file.logical,
                iso8601(file.modified),
                file.attributes,
            )
        })
        .collect()
}

fn extension_rows(scan: &Scan) -> Vec<(&str, u64, u64)> {
    let mut rows: Vec<(&str, u64, u64)> = scan
        .tally
        .extensions
        .iter()
        .map(|(extension, (allocated, count))| (extension.as_str(), *allocated, *count))
        .collect();
    rows.sort_by(|a, b| b.1.cmp(&a.1).then_with(|| a.0.cmp(b.0)));
    rows.truncate(DU_TOP_EXTENSIONS);
    rows
}
